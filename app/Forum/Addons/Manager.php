<?php

namespace App\Forum\Addons;

use App\Forum\License\Licenses;
use App\Forum\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Throwable;
use ZipArchive;

/** Finds the add-ons in addons/, switches them on and off and boots the active ones. */
class Manager
{
    /** @var array<string, array<string, mixed>>|null id => manifest (+ "path") */
    protected ?array $found = null;

    /** @var array<string, Addon> */
    protected array $booted = [];

    /** @var array<string, string> id => error message */
    protected array $errors = [];

    /** @var array<string, array<string, string>> language lines of add-ons, read for their names and descriptions */
    protected array $lines = [];

    public function __construct(protected Settings $settings) {}

    public function root(): string
    {
        return config('forum.addons_path') ?: base_path('addons');
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->found !== null) {
            return $this->found;
        }

        $this->found = [];
        // Not glob(): it cannot read folders with non-ASCII names on Windows.
        $files = [];
        foreach (is_dir($this->root()) ? File::directories($this->root()) : [] as $vendor) {
            foreach (File::directories($vendor) as $directory) {
                if (is_file($directory.DIRECTORY_SEPARATOR.'addon.json')) {
                    $files[] = $directory.DIRECTORY_SEPARATOR.'addon.json';
                }
            }
        }

        foreach ($files as $file) {
            $manifest = json_decode((string) file_get_contents($file), true);
            if (is_array($manifest) && $this->validId($manifest['id'] ?? null) && is_string($manifest['namespace'] ?? null)) {
                $this->found[$manifest['id']] = $manifest + ['title' => $manifest['id'], 'version' => '1.0.0', 'path' => dirname($file)];
            }
        }
        ksort($this->found);

        return $this->found;
    }

    /**
     * The name or the description of an add-on in the forum's language. The add-on's own language file is read
     * directly, so this also works while the add-on is switched off and none of its code runs.
     */
    public function text(string $id, string $field = 'title'): string
    {
        $addon = $this->all()[$id] ?? null;
        $english = (string) ($addon[$field] ?? ($field === 'title' ? $id : ''));
        if (! $addon || $english === '') {
            return $english;
        }

        $locale = app()->getLocale();
        $lines = $this->lines[$id.'|'.$locale] ??= (function () use ($addon, $locale) {
            $file = $addon['path'].DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.$locale.'.json';
            $read = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

            return is_array($read) ? $read : [];
        })();

        return (string) ($lines[$english] ?? __($english));
    }

    /** Where an add-on option is kept in the settings table. */
    public static function settingKey(string $id, string $name): string
    {
        return 'addon:'.$id.':'.$name;
    }

    /** @return array<string, array{label: string, type: string, default: mixed}> options an add-on declares in addon.json */
    public function settingsOf(string $id): array
    {
        $declared = $this->all()[$id]['settings'] ?? [];

        return collect(is_array($declared) ? $declared : [])
            ->filter(fn ($def, $name) => is_array($def) && is_string($name) && preg_match('/^[a-z][a-z0-9_]*$/', $name))
            ->map(fn (array $def, string $name) => $def + ['label' => $name, 'type' => 'string', 'default' => ''])
            ->all();
    }

    /**
     * Licence product of a sold add-on ("license" in addon.json), null for an add-on that is free to run.
     * A sold add-on only runs while a licence code for that product is active on this forum.
     */
    public function licenseProduct(string $id): ?string
    {
        $product = $this->all()[$id]['license'] ?? null;

        return Licenses::validProduct($product) ? $product : null;
    }

    public function licensed(string $id): bool
    {
        $product = $this->licenseProduct($id);

        return $product === null || app(Licenses::class)->valid($product);
    }

    /** @return string[] add-ons that are switched on and may run */
    public function enabledIds(): array
    {
        return array_values(array_filter($this->storedIds(), fn (string $id) => $this->licensed($id)));
    }

    /** @return string[] switched on, but held back until their licence is in order again */
    public function lockedIds(): array
    {
        return array_values(array_diff($this->storedIds(), $this->enabledIds()));
    }

    /** @return string[] what the administrator switched on, licence or not */
    public function storedIds(): array
    {
        return array_values(array_intersect((array) $this->settings->get('addons.enabled', []), array_keys($this->all())));
    }

    public function isEnabled(string $id): bool
    {
        return in_array($id, $this->enabledIds(), true);
    }

    public function error(string $id): ?string
    {
        return $this->errors[$id] ?? null;
    }

    /** Called while the application starts: load every active add-on. */
    public function bootEnabled(): void
    {
        if (config('forum.safe_mode')) {
            return;
        }

        try {
            $ids = $this->enabledIds();
        } catch (Throwable) {
            return; // not installed yet
        }

        foreach ($ids as $id) {
            $this->boot($id);
        }
    }

    public function enable(string $id): ?string
    {
        if (! isset($this->all()[$id])) {
            return __('This add-on does not exist.');
        }
        if (! $this->licensed($id)) {
            return __('Enter the licence code of this module first.');
        }

        $addon = $this->boot($id);
        if (! $addon) {
            return $this->errors[$id] ?? __('The add-on could not be started.');
        }

        try {
            if (is_dir($addon->path('migrations'))) {
                Artisan::call('migrate', ['--path' => $addon->path('migrations'), '--realpath' => true, '--force' => true]);
            }
            $addon->install();
        } catch (Throwable $e) {
            report($e);

            return $e->getMessage();
        }

        $this->settings->set(['addons.enabled' => array_values(array_unique([...$this->storedIds(), $id]))]);

        return null;
    }

    public function disable(string $id): void
    {
        try {
            ($this->booted[$id] ?? null)?->uninstall();
        } catch (Throwable $e) {
            report($e);
        }

        $this->settings->set(['addons.enabled' => array_values(array_diff($this->storedIds(), [$id]))]);
    }

    /**
     * Installs (or replaces) an add-on from a .zip: addon.json at the top, or inside one folder.
     *
     * @return array{id: string, title: string}|string the add-on, or why it was refused
     */
    public function installZip(string $zipPath, ?string $expectedId = null): array|string
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return __('The package could not be opened.');
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $prefix = in_array('addon.json', $names, true) ? '' : (explode('/', $names[0] ?? '')[0].'/');
        $manifest = json_decode((string) $zip->getFromName($prefix.'addon.json'), true);

        if (! is_array($manifest) || ! $this->validId($manifest['id'] ?? null) || ($expectedId !== null && $manifest['id'] !== $expectedId)) {
            $zip->close();

            return __('This does not look like an add-on (addon.json is missing or has no valid id).');
        }
        if (in_array($manifest['id'], $this->storedIds(), true)) {
            $zip->close();

            return __('Switch the add-on off before installing a new copy of it.');
        }

        $target = $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $manifest['id']);
        File::deleteDirectory($target);

        foreach ($names as $i => $name) {
            $relative = $prefix === '' ? $name : (str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : null);
            if ($relative === null || $relative === '' || str_ends_with($relative, '/') || str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, "\0")) {
                continue;
            }

            $path = $target.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, $zip->getFromIndex($i));
            // The next request must run the new code, not what the opcode cache still holds of the old copy.
            if (str_ends_with($path, '.php') && function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
        }
        $zip->close();
        $this->forget();

        return ['id' => $manifest['id'], 'title' => $this->text($manifest['id'])];
    }

    /** Remove the files of an add-on that is switched off. */
    public function delete(string $id): bool
    {
        $manifest = $this->all()[$id] ?? null;
        if (! $manifest || in_array($id, $this->storedIds(), true)) {
            return false;
        }

        File::deleteDirectory($manifest['path']);
        $this->found = null;

        return true;
    }

    public function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('~^[A-Za-z][A-Za-z0-9]*/[A-Za-z][A-Za-z0-9]*$~', $id) === 1;
    }

    public function forget(): void
    {
        $this->found = null;
    }

    protected function boot(string $id): ?Addon
    {
        if (isset($this->booted[$id])) {
            return $this->booted[$id];
        }

        $manifest = $this->all()[$id];
        $namespace = trim($manifest['namespace'], '\\').'\\';
        $source = $manifest['path'].DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR;

        // PSR-4: Vendor\Name\Foo\Bar -> addons/Vendor/Name/src/Foo/Bar.php
        spl_autoload_register(function (string $class) use ($namespace, $source) {
            if (str_starts_with($class, $namespace)) {
                $file = $source.str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($namespace))).'.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        });

        try {
            $class = $namespace.($manifest['main'] ?? 'Addon');
            $addon = new $class($manifest['path'], $manifest);
            if (! $addon instanceof Addon) {
                throw new \RuntimeException($class.' must extend '.Addon::class);
            }
            $addon->boot();
        } catch (Throwable $e) {
            report($e);
            $this->errors[$id] = $e->getMessage();

            return null;
        }

        return $this->booted[$id] = $addon;
    }
}
