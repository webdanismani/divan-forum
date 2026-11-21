<?php

namespace App\Forum\Addons;

use App\Forum\Settings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

/**
 * Base class of an add-on. An add-on lives in addons/{Vendor}/{Name}/ with an
 * addon.json next to a src/ folder; its main class extends this one and
 * registers what it adds in boot(). See addons/README.md.
 */
abstract class Addon
{
    /** @param array<string, mixed> $manifest contents of addon.json */
    public function __construct(protected string $path, protected array $manifest) {}

    /** Called on every request while the add-on is switched on. */
    abstract public function boot(): void;

    /** Called once when an administrator switches the add-on on (after its migrations ran). */
    public function install(): void {}

    /** Called when an administrator switches the add-on off. Keep the data unless told otherwise. */
    public function uninstall(): void {}

    public function id(): string
    {
        return (string) $this->manifest['id'];
    }

    public function path(string $relative = ''): string
    {
        return $this->path.($relative !== '' ? DIRECTORY_SEPARATOR.$relative : '');
    }

    /** Short name for views and translations: "vendor/name" becomes "vendor-name". */
    public function key(): string
    {
        return str_replace('/', '-', strtolower($this->id()));
    }

    /**
     * An option of this add-on. Options are declared in addon.json ("settings") and edited by
     * administrators under Add-ons > Settings.
     */
    public function setting(string $name, mixed $default = null): mixed
    {
        $declared = $this->manifest['settings'][$name]['default'] ?? $default;

        return app(Settings::class)->get(Manager::settingKey($this->id(), $name), $declared) ?? $declared;
    }

    /** Routes from a file, inside the normal "web" middleware (sessions, sign-in, locale). */
    protected function loadRoutes(string $file = 'routes.php'): void
    {
        if (is_file($this->path($file))) {
            Route::middleware('web')->group($this->path($file));
            // Route names are indexed once at start-up; routes added later have to be indexed again.
            Route::getRoutes()->refreshNameLookups();
            Route::getRoutes()->refreshActionLookups();
        }
    }

    /** Views as "{key}::name", e.g. view('meydan-example::hello'). */
    protected function loadViews(string $directory = 'views'): void
    {
        if (is_dir($this->path($directory))) {
            View::addNamespace($this->key(), $this->path($directory));
        }
    }

    /** JSON translation files (tr.json, en.json ...) with whole-sentence keys. */
    protected function loadTranslations(string $directory = 'lang'): void
    {
        if (is_dir($this->path($directory))) {
            app('translator')->addJsonPath($this->path($directory));
            // Lines that were already read for this language are read again, now with the add-on's file.
            app('translator')->setLoaded([]);
        }
    }

    protected function hook(string $name, callable $listener): void
    {
        app(Hooks::class)->listen($name, $listener);
    }

    /** Forum events: forum.thread.created, forum.post.created, forum.user.registered. */
    protected function on(string $event, callable $listener): void
    {
        Event::listen($event, $listener);
    }
}
