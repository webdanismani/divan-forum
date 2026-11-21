<?php

namespace App\Forum\Translation;

use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Wraps the file loader: texts changed in the admin panel win over lang/{locale}.json. */
class OverridingLoader implements Loader
{
    public function __construct(protected Loader $files) {}

    public static function cacheKey(string $locale): string
    {
        return 'forum.phrases.'.$locale;
    }

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->files->load($locale, $group, $namespace);

        // "*" / "*" is the JSON file with the whole-sentence keys.
        if ($group !== '*' || $namespace !== '*') {
            return $lines;
        }

        try {
            $overrides = Cache::rememberForever(self::cacheKey($locale), fn () => DB::table('phrases')->where('locale', $locale)->pluck('value', 'key')->all());
        } catch (Throwable) {
            $overrides = []; // table not migrated yet
        }

        return $overrides + $lines;
    }

    public function addNamespace($namespace, $hint)
    {
        $this->files->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->files->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->files->namespaces();
    }
}
