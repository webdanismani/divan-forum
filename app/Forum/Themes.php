<?php

namespace App\Forum;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Installable themes: a package with theme.json (title, version, colours),
 * one optional stylesheet and, in a folder named "assets", the fonts and
 * pictures that stylesheet uses. A theme never contains code that runs on
 * the server; switching one on sets the board colours and adds its CSS.
 */
class Themes
{
    protected const KEY = 'theme.active';

    /** Largest stylesheet a theme may bring. */
    protected const MAX_CSS = 300 * 1024;

    /** Files a theme may bring along for its stylesheet: fonts and pictures, nothing a browser would run. */
    public const ASSET_TYPES = [
        'woff2' => 'font/woff2',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
    ];

    protected const MAX_ASSET = 600 * 1024;

    protected const MAX_ASSETS = 40;

    protected ?array $found = null;

    public function __construct(protected Settings $settings, protected Theme $theme) {}

    public function root(): string
    {
        return config('forum.themes_path') ?: storage_path('app/themes');
    }

    /** @return array<string, array<string, mixed>> id => manifest (+ "path") */
    public function all(): array
    {
        if ($this->found !== null) {
            return $this->found;
        }

        $this->found = [];
        foreach (is_dir($this->root()) ? File::directories($this->root()) : [] as $vendor) {
            foreach (File::directories($vendor) as $directory) {
                $manifest = json_decode((string) @file_get_contents($directory.DIRECTORY_SEPARATOR.'theme.json'), true);
                if (is_array($manifest) && self::validId($manifest['id'] ?? null)) {
                    $this->found[$manifest['id']] = $manifest + ['title' => $manifest['id'], 'version' => '1.0.0', 'path' => $directory];
                }
            }
        }
        ksort($this->found);

        return $this->found;
    }

    /** The theme in use. A sold theme whose licence is not in order counts as switched off. */
    public function activeId(): ?string
    {
        $id = $this->chosenId();

        return $id !== null && $this->licensed($id) ? $id : null;
    }

    /** What the administrator switched on, licence or not. */
    public function chosenId(): ?string
    {
        $id = $this->settings->get(self::KEY);

        return is_string($id) && isset($this->all()[$id]) ? $id : null;
    }

    /** Licence product of a sold theme ("license" in theme.json), null for a theme that is free to use. */
    public function licenseProduct(string $id): ?string
    {
        $product = $this->all()[$id]['license'] ?? null;

        return License\Licenses::validProduct($product) ? $product : null;
    }

    public function licensed(string $id): bool
    {
        $product = $this->licenseProduct($id);

        return $product === null || app(License\Licenses::class)->valid($product);
    }

    /** Scheduled: a sold theme that lost its licence is switched off, so its colours do not stay behind. */
    public function dropUnlicensed(): bool
    {
        $id = $this->chosenId();
        if ($id === null || $this->licensed($id)) {
            return false;
        }
        $this->deactivate();

        return true;
    }

    public function activate(string $id): bool
    {
        $manifest = $this->all()[$id] ?? null;
        if (! $manifest || ! $this->licensed($id)) {
            return false;
        }

        // The theme that was in use takes its home page sections with it.
        $home = app(Home::class);
        if (($previous = $this->chosenId()) !== null && $previous !== $id) {
            $home->release($previous);
        }

        // Colours the theme does not name fall back to the defaults.
        $this->theme->save(is_array($manifest['colors'] ?? null) ? $manifest['colors'] : []);
        $this->settings->set([self::KEY => $id]);

        // A theme designed for certain home page sections brings them along the first time it is switched on.
        if (isset($manifest['home']) && $home->adopted() !== $id) {
            $home->adopt($manifest);
        }

        return true;
    }

    /** A value from the manifest of the theme in use ("header", "home", …); the default when no theme is on. */
    public function option(string $key, mixed $default = null): mixed
    {
        if (config('forum.safe_mode') || ! ($id = $this->activeId())) {
            return $default;
        }

        return data_get($this->all()[$id], $key, $default);
    }

    public function deactivate(): void
    {
        // Nothing of the theme stays behind: its colours go, and so do the home page sections it switched on.
        if (($id = $this->chosenId()) !== null) {
            app(Home::class)->release($id);
        }
        $this->theme->reset();
        $this->settings->set([self::KEY => null]);
    }

    public function delete(string $id): bool
    {
        $manifest = $this->all()[$id] ?? null;
        if (! $manifest || $this->chosenId() === $id) {
            return false;
        }

        File::deleteDirectory($manifest['path']);
        $this->found = null;

        return true;
    }

    /** Stylesheet of the active theme, safe to print inside a <style> element. */
    public function css(): string
    {
        if (config('forum.safe_mode') || ! ($id = $this->activeId())) {
            return '';
        }

        $manifest = $this->all()[$id];
        $file = basename((string) ($manifest['css'] ?? ''));
        $path = $manifest['path'].DIRECTORY_SEPARATOR.$file;

        if ($file === '' || ! str_ends_with($file, '.css') || ! is_file($path)) {
            return '';
        }

        // "theme://name.woff2" in the stylesheet stands for a file from the theme's assets folder.
        $base = url('theme-assets/'.$id);
        $version = rawurlencode((string) $manifest['version']);

        return preg_replace_callback(
            '~theme://([A-Za-z0-9][A-Za-z0-9._-]*)~',
            fn (array $match) => $base.'/'.$match[1].'?v='.$version,
            str_replace('<', '', (string) file_get_contents($path)),
        );
    }

    /** Full path of a file from a theme's assets folder, or null when there is no such file. */
    public function asset(string $id, string $file): ?string
    {
        $manifest = $this->all()[$id] ?? null;
        if (! $manifest || ! self::validAsset($file)) {
            return null;
        }
        $path = $manifest['path'].DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.$file;

        return is_file($path) ? $path : null;
    }

    public static function validAsset(string $file): bool
    {
        return preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.([A-Za-z0-9]+)$~', $file, $match) === 1
            && isset(self::ASSET_TYPES[strtolower($match[1])]);
    }

    /**
     * Installs (or replaces) a theme from a .zip.
     *
     * @return array{id: string, title: string, version: string}|string the theme, or why it was refused
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
        $prefix = in_array('theme.json', $names, true) ? '' : (explode('/', $names[0] ?? '')[0].'/');
        $json = (string) $zip->getFromName($prefix.'theme.json');
        $manifest = json_decode($json, true);

        if (! is_array($manifest) || ! self::validId($manifest['id'] ?? null) || ($expectedId !== null && $manifest['id'] !== $expectedId)) {
            $zip->close();

            return __('This does not look like a theme (theme.json is missing or has no valid id).');
        }

        // Only the manifest, the stylesheet and the fonts and pictures in "assets" are taken out of the package; nothing else is stored.
        $cssName = basename((string) ($manifest['css'] ?? ''));
        $css = $cssName !== '' && str_ends_with($cssName, '.css') ? $zip->getFromName($prefix.$cssName) : false;

        $assets = [];
        foreach ($names as $name) {
            if (! str_starts_with($name, $prefix.'assets/') || str_ends_with($name, '/')) {
                continue;
            }
            $file = substr($name, strlen($prefix.'assets/'));
            $size = $zip->statName($name)['size'] ?? PHP_INT_MAX;
            if (! self::validAsset($file) || $size > self::MAX_ASSET || count($assets) >= self::MAX_ASSETS) {
                $zip->close();

                return __('This theme brings a file the forum does not accept (:file).', ['file' => $file]);
            }
            $assets[$file] = $zip->getFromName($name);
        }
        $zip->close();

        if (is_string($css) && strlen($css) > self::MAX_CSS) {
            return __('The stylesheet of this theme is too large.');
        }

        $target = $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $manifest['id']);
        File::deleteDirectory($target);
        File::ensureDirectoryExists($target);
        file_put_contents($target.DIRECTORY_SEPARATOR.'theme.json', $json);
        if (is_string($css)) {
            file_put_contents($target.DIRECTORY_SEPARATOR.$cssName, $css);
        }
        if ($assets) {
            File::ensureDirectoryExists($target.DIRECTORY_SEPARATOR.'assets');
            foreach ($assets as $file => $contents) {
                file_put_contents($target.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.$file, (string) $contents);
            }
        }
        $this->found = null;

        // A new copy of the theme in use takes effect straight away; a sold theme that arrives without its
        // licence is switched off instead.
        if ($this->chosenId() === $manifest['id']) {
            $this->licensed($manifest['id']) ? $this->activate($manifest['id']) : $this->deactivate();
        }

        return ['id' => $manifest['id'], 'title' => (string) ($manifest['title'] ?? $manifest['id']), 'version' => (string) ($manifest['version'] ?? '1.0.0')];
    }

    public static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('~^[A-Za-z][A-Za-z0-9]*/[A-Za-z][A-Za-z0-9]*$~', $id) === 1;
    }
}
