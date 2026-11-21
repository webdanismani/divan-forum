<?php

namespace App\Forum\Moderation;

use App\Forum\Settings;

/**
 * Word filter. The list in the settings has one entry per line:
 *   word            -> replaced by the default mask
 *   word=other      -> replaced by "other"
 *   wor*            -> "*" matches the rest of the word
 */
class Censor
{
    /** @var array<int, array{0: string, 1: string}>|null regular expression and replacement */
    protected ?array $rules = null;

    public function __construct(protected Settings $settings) {}

    public function clean(?string $text): string
    {
        $text = (string) $text;
        if ($text === '') {
            return $text;
        }

        foreach ($this->rules() as [$pattern, $replacement]) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }

    public function flush(): void
    {
        $this->rules = null;
    }

    protected function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $mask = (string) $this->settings->get('censor.mask') ?: '***';
        $rules = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $this->settings->get('censor.words')) as $line) {
            [$word, $replacement] = array_map('trim', explode('=', $line, 2) + [1 => $mask]);
            if (mb_strlen($word) < 2) {
                continue;
            }

            $body = str_replace('\*', '[\p{L}\p{N}]*', preg_quote($word, '/'));
            // Whole words only, in any letter case, Turkish letters included.
            $rules[] = ['/(?<![\p{L}\p{N}])'.$body.'(?![\p{L}\p{N}])/iu', str_replace(['\\', '$'], ['\\\\', '\$'], $replacement !== '' ? $replacement : $mask)];
        }

        return $this->rules = $rules;
    }
}
