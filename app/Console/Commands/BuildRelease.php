<?php

namespace App\Console\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Packs the forum into dist/meydan-{version}.zip. The same file serves as the download for new
 * installations and as the "Forum güncellemesi" product in the market (one-click update).
 */
class BuildRelease extends Command
{
    protected $signature = 'forum:release {--without-vendor : Leave out vendor/ (only when no dependency changed since the last release)}';

    protected $description = 'Build the release / update package of the forum into dist/';

    /** What a forum installation consists of. Sold modules, the market server and tests stay out. */
    protected const PATHS = ['app', 'bootstrap/app.php', 'bootstrap/providers.php', 'config', 'database/migrations', 'database/factories', 'database/seeders', 'lang', 'public', 'resources', 'routes', 'addons', 'artisan', 'composer.json', 'composer.lock', 'README.md', 'README.tr.md', 'LICENSE', 'LICENSE.tr.md', '.env.example'];

    protected const SKIP = ['public/storage', 'public/hot', 'public/build', 'public/_shot'];

    /** Of the add-ons folder only this goes out: the example and the notes for developers. Modules installed here are sold separately. */
    protected const ADDONS = ['addons/Meydan/', 'addons/README.md'];

    public function handle(): int
    {
        $version = (string) config('forum.product.version');
        $target = base_path('dist/meydan-'.$version.'.zip');
        @mkdir(dirname($target), 0777, true);
        @unlink($target);

        $files = [];
        foreach (self::PATHS as $path) {
            foreach ($this->filesUnder($path) as $file) {
                $files[] = $file;
            }
        }

        // The autoloader goes in last: while the package is unpacked over a running forum,
        // the old autoloader keeps pointing at files that exist.
        $last = [];
        if (! $this->option('without-vendor')) {
            foreach ($this->filesUnder('vendor') as $file) {
                str_starts_with($file, 'vendor/composer/') || $file === 'vendor/autoload.php' ? $last[] = $file : $files[] = $file;
            }
        }

        $zip = new ZipArchive;
        $zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ([...$files, ...$last] as $file) {
            $zip->addFile(base_path($file), 'meydan/'.$file);
        }
        $zip->close();

        $this->components->info(sprintf('%s: %d files, %.1f MB', 'dist/'.basename($target), count($files) + count($last), filesize($target) / 1048576));
        $this->line('  Upload it in the market panel as the product of type "Forum güncellemesi"; forums then see "Update to '.$version.'".');

        return self::SUCCESS;
    }

    /** @return string[] paths relative to the project root, with forward slashes */
    protected function filesUnder(string $path): array
    {
        $full = base_path($path);
        if (is_file($full)) {
            return [$path];
        }
        if (! is_dir($full)) {
            return [];
        }

        $out = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = $path.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($full) + 1));
            foreach (self::SKIP as $skip) {
                if ($relative === $skip || str_starts_with($relative, $skip.'/')) {
                    continue 2;
                }
            }
            if (str_starts_with($relative, 'addons/') && ! collect(self::ADDONS)->contains(fn ($keep) => $relative === $keep || str_starts_with($relative, $keep))) {
                continue;
            }
            $out[] = $relative;
        }

        return $out;
    }
}
