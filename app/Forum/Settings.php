<?php

namespace App\Forum;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Board options: defaults from config/forum.php overridden by the settings table. */
class Settings
{
    protected const CACHE_KEY = 'forum.settings';

    protected ?array $values = null;

    /** @return array<string, array> flat definition list keyed by option key */
    public function definitions(): array
    {
        return collect(config('forum.settings'))->collapse()->all();
    }

    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $defaults = array_map(fn (array $def) => $def['default'], $this->definitions());

        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::pluck('value', 'key')->all());
        } catch (Throwable) {
            $stored = []; // table not migrated yet (fresh install)
        }

        foreach ($stored as $key => $value) {
            $stored[$key] = json_decode((string) $value, true);
        }

        return $this->values = array_merge($defaults, $stored);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
        }
        $this->flush();
    }

    public function flush(): void
    {
        $this->values = null;
        Cache::forget(self::CACHE_KEY);
    }
}
