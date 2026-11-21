<?php

namespace App\Forum;

/**
 * Thread titles in one shape: every word starts with a capital letter ("örnek test konusu" → "Örnek Test Konusu").
 * A title typed all in capitals is calmed down first. Words that carry their own capitals (iPhone, WordPress, SEO
 * inside a mixed title) and addresses are left alone.
 */
class Titles
{
    public static function tidy(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title));
        if ($title === '' || ! setting('posting.title_case')) {
            return $title;
        }
        $turkish = in_array(app()->getLocale(), ['tr', 'az'], true);

        // Shouting: four or more letters and not one of them lower case.
        $letters = preg_replace('/[^\p{L}]/u', '', $title);
        if (mb_strlen($letters) >= 4 && ! preg_match('/\p{Ll}/u', $letters)) {
            $title = self::lower($title, $turkish);
        }

        $words = array_map(function (string $word) use ($turkish) {
            if (str_contains($word, '://') || preg_match('/^www\./i', $word) || preg_match('/^.+\p{Lu}/u', $word)) {
                return $word; // an address, or a word with capitals of its own
            }

            // The first letter of the word and of each part after a hyphen or a slash; opening brackets and quotes are skipped.
            return preg_replace_callback('/(?:^[(\[{"“«\'‘]*|[\/-])\K\p{Ll}/u', fn ($m) => self::upper($m[0], $turkish), $word);
        }, explode(' ', $title));

        return implode(' ', $words);
    }

    protected static function upper(string $letter, bool $turkish): string
    {
        return mb_strtoupper($turkish ? str_replace(['i', 'ı'], ['İ', 'I'], $letter) : $letter);
    }

    protected static function lower(string $text, bool $turkish): string
    {
        return mb_strtolower($turkish ? str_replace(['I', 'İ'], ['ı', 'i'], $text) : $text);
    }
}
