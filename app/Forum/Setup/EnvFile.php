<?php

namespace App\Forum\Setup;

/** Reads and rewrites KEY=value lines of an .env file without touching the rest. */
class EnvFile
{
    /** @param  array<string, string|int|bool|null>  $values */
    public static function render(string $template, array $values): string
    {
        $lines = preg_split('/\r\n|\r|\n/', rtrim($template));
        $seen = [];

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*#?\s*([A-Z][A-Z0-9_]*)=/', $line, $m) && array_key_exists($m[1], $values) && ! isset($seen[$m[1]])) {
                $lines[$i] = $m[1].'='.self::quote($values[$m[1]]);
                $seen[$m[1]] = true;
            }
        }

        foreach ($values as $key => $value) {
            if (! isset($seen[$key])) {
                $lines[] = $key.'='.self::quote($value);
            }
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    public static function quote(string|int|bool|null $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@+=-]+$/', $value)) {
            return $value;
        }

        // Line breaks cannot be represented; quotes, backslashes and "$" are escaped.
        $value = str_replace(["\r", "\n"], '', $value);

        return '"'.addcslashes($value, '\\"$').'"';
    }
}
