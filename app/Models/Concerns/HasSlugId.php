<?php

namespace App\Models\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * Search-engine friendly route keys in the form "some-title-123": the words of the title in
 * lower case with hyphens, then the number.
 *
 * Only the number is used for the lookup, so a link keeps working after a rename. A visit to an
 * outdated, shortened or misspelt address is sent on (301) to the current one, so every page has
 * exactly one address. The earlier form "some-title.123" is still understood and sent on as well.
 */
trait HasSlugId
{
    public function getRouteKey(): string
    {
        $slug = Str::slug((string) $this->{$this->slugSource});

        return ($slug !== '' ? $slug.'-' : '').$this->getKey();
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $value = (string) $value;
        if (preg_match('/(?:^|[.-])(\d{1,18})$/', $value, $match) !== 1) {
            return null;
        }

        $model = $this->newQuery()->find((int) $match[1]);
        if ($model && $value !== $model->getRouteKey()) {
            $this->sendToCanonical($value, $model->getRouteKey());
        }

        return $model;
    }

    /** Pages asked for by a browser or a crawler under another spelling move to the one true address. */
    protected function sendToCanonical(string $given, string $canonical): void
    {
        $request = request();
        if (! $request->isMethodSafe() || $request->ajax() || $request->expectsJson() || $request->is('api/*')) {
            return;
        }

        $path = $request->getPathInfo();
        $needle = '/'.$given;
        $at = strpos($path, $needle.'/');
        if ($at === false && str_ends_with($path, $needle)) {
            $at = strlen($path) - strlen($needle);
        }
        if ($at === false) {
            return;
        }

        $query = $request->getQueryString();
        $target = $request->getBaseUrl().substr($path, 0, $at).'/'.$canonical.substr($path, $at + strlen($needle)).($query ? '?'.$query : '');

        throw new HttpResponseException(redirect()->to($target, 301));
    }
}
