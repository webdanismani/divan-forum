<?php

namespace App\Forum;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ParseError;
use Throwable;

/**
 * Changes an administrator makes to the look without touching the package
 * files: extra CSS, HTML snippets, a logo and edited copies of templates.
 * Everything here is skipped in safe mode (FORUM_SAFE_MODE=true in .env),
 * the way back in when a change broke the pages.
 */
class Customizer
{
    public function __construct(protected Settings $settings) {}

    public function safeMode(): bool
    {
        return (bool) config('forum.safe_mode');
    }

    public function css(): string
    {
        // Nothing here may close the <style> element it is printed in.
        return $this->safeMode() ? '' : str_replace('<', '', (string) $this->settings->get('theme.custom_css'));
    }

    /** Raw HTML written by an administrator (analytics tags and the like). */
    public function html(string $place): string
    {
        return $this->safeMode() ? '' : (string) $this->settings->get('theme.'.$place.'_html');
    }

    public function logoUrl(): ?string
    {
        $path = (string) $this->settings->get('theme.logo_path');

        return $path !== '' ? Storage::disk('public')->url($path) : null;
    }

    // ---- Template overrides ---------------------------------------------------------

    public function overridePath(string $relative = ''): string
    {
        return storage_path('app/templates'.($relative !== '' ? DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative) : ''));
    }

    /** @return string[] template files of the package, relative to resources/views */
    public function templates(): array
    {
        $root = resource_path('views');
        $files = [];
        foreach (File::allFiles($root) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }
        sort($files);

        return $files;
    }

    public function isTemplate(string $relative): bool
    {
        return in_array($relative, $this->templates(), true);
    }

    public function isOverridden(string $relative): bool
    {
        return is_file($this->overridePath($relative));
    }

    /** @return string[] */
    public function overridden(): array
    {
        return array_values(array_filter($this->templates(), fn (string $file) => $this->isOverridden($file)));
    }

    public function source(string $relative, bool $original = false): string
    {
        return File::get(! $original && $this->isOverridden($relative) ? $this->overridePath($relative) : resource_path('views/'.$relative));
    }

    /** @return string|null what is wrong with the template, null when it compiles */
    public function check(string $content): ?string
    {
        try {
            token_get_all(Blade::compileString($content), TOKEN_PARSE);
        } catch (ParseError $e) {
            return $e->getMessage().' ('.__('line').' '.$e->getLine().')';
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function save(string $relative, string $content): void
    {
        $path = $this->overridePath($relative);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);
    }

    public function revert(string $relative): void
    {
        File::delete($this->overridePath($relative));
    }
}
