<?php

namespace App\Forum\Setup;

use App\Forum\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Throwable;
use ZipArchive;

/**
 * Installs an update package of the forum itself: unpacks it over the
 * application, then updates the database and whatever is cached. Used by the
 * upload form under Maintenance and by the one-click update in the market.
 */
class Updater
{
    /** Never taken from an update package. */
    protected const PROTECTED_PATHS = ['.env', 'storage/', 'database/database.sqlite', 'bootstrap/cache/', 'public/storage', 'public/index.php', 'addons/'];

    /**
     * @return array{written: int, skipped: int, log: string}|string the result, or why the package was refused
     */
    public function applyZip(string $zipPath): array|string
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return __('The package could not be opened.');
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        // A package may be zipped with or without one top-level folder.
        $prefix = '';
        if (! in_array('config/forum.php', $names, true)) {
            $first = explode('/', $names[0] ?? '')[0];
            if ($first !== '' && in_array($first.'/config/forum.php', $names, true)) {
                $prefix = $first.'/';
            } else {
                $zip->close();

                return __('This does not look like an update package (config/forum.php is missing).');
            }
        }

        $written = 0;
        $skipped = 0;
        foreach ($names as $i => $name) {
            $relative = $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : ($prefix === '' ? $name : null);

            if ($relative === null || $relative === '' || str_ends_with($relative, '/')) {
                continue;
            }
            if (str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, "\0") || $this->isProtected($relative)) {
                $skipped++;

                continue;
            }

            $target = $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            File::ensureDirectoryExists(dirname($target));
            file_put_contents($target, $zip->getFromIndex($i));
            $written++;

            // Hosts where the document root is not the package's public/ folder.
            if (! config('forum.update_root') && str_starts_with($relative, 'public/') && realpath(public_path()) !== realpath(base_path('public'))) {
                $public = public_path(substr($relative, 7));
                File::ensureDirectoryExists(dirname($public));
                file_put_contents($public, $zip->getFromIndex($i));
            }
        }
        $zip->close();

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return ['written' => $written, 'skipped' => $skipped, 'log' => __(':written files were updated, :skipped were skipped.', ['written' => $written, 'skipped' => $skipped])."\n".$this->refresh()];
    }

    /** Where the application lives (tests point this at a throw-away folder). */
    protected function root(): string
    {
        return config('forum.update_root') ?: base_path();
    }

    /** Run pending migrations and rebuild whatever is cached. */
    public function refresh(): string
    {
        $log = '';
        $cached = app()->configurationIsCached();

        foreach (array_filter([['migrate', ['--force' => true]], ['view:clear', []], $cached ? ['config:cache', []] : null]) as [$command, $arguments]) {
            try {
                Artisan::call($command, $arguments);
                $log .= '$ '.$command."\n".trim(Artisan::output())."\n";
            } catch (Throwable $e) {
                report($e);
                $log .= '$ '.$command."\n".$e->getMessage()."\n";
            }
        }

        app(Settings::class)->flush();

        return trim($log);
    }

    protected function isProtected(string $relative): bool
    {
        foreach (self::PROTECTED_PATHS as $path) {
            if ($relative === rtrim($path, '/') || str_starts_with($relative, $path)) {
                return true;
            }
        }

        return false;
    }
}
