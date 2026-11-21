<?php

namespace App\Forum\Security;

/**
 * Finds site addresses in plain profile text, also when they are disguised:
 * "site.com", "www site com", "site . com", "site nokta com", "site(dot)com", "s i t e . c o m", "site,com".
 * It reads; it does not change the text. The caller decides what to do with a hit.
 */
class LinkGuard
{
    /** Endings that count when they directly follow a dot ("name.ending"). */
    protected const ENDINGS = [
        'com', 'net', 'org', 'info', 'biz', 'xyz', 'online', 'site', 'store', 'shop', 'app', 'dev', 'io', 'co', 'me', 'tv', 'cc', 'ws',
        'pro', 'club', 'top', 'vip', 'live', 'link', 'click', 'blog', 'tech', 'web', 'website', 'space', 'fun', 'icu', 'cloud', 'agency',
        'email', 'media', 'news', 'today', 'world', 'life', 'ltd', 'name', 'mobi', 'asia', 'tel', 'travel', 'gov', 'edu', 'ist', 'istanbul',
        'bet', 'casino', 'win', 'games', 'art', 'one', 'network', 'digital', 'studio', 'design', 'host', 'page', 'bio', 'gg', 'ly', 'gl',
        'tr', 'de', 'uk', 'us', 'ru', 'fr', 'nl', 'it', 'es', 'eu', 'az', 'ir', 'in', 'cn', 'jp', 'kr', 'br', 'ca', 'au', 'ch', 'at', 'be',
        'se', 'dk', 'fi', 'pl', 'cz', 'gr', 'bg', 'ro', 'ua', 'kz', 'to', 'im', 'ai', 'sh', 'id', 'pk', 'sa', 'ae', 'qa', 'cy', 'ge',
    ];

    /** Endings nobody writes by accident: these also count with spaces, a comma or no dot at all before them. */
    protected const SURE = ['com', 'org'];

    /** What stands before ".tr" in Turkish addresses ("site net tr"). */
    protected const BEFORE_TR = ['com', 'net', 'org', 'gen', 'web', 'biz', 'info', 'name', 'tv', 'av', 'dr', 'bel', 'k12', 'edu', 'gov', 'bbs', 'tel'];

    /** Product names that look like an address and are not one. */
    protected const NOT_ADDRESSES = ['asp.net', 'ado.net', 'vb.net', 'ml.net'];

    /**
     * @param  array<int, string>  $allowed  hosts that may be written (their subdomains too)
     * @return string|null the piece of text that reads as an address, as the member wrote it where possible
     */
    public function find(?string $text, array $allowed = []): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        $allowed = array_values(array_filter(array_map(fn ($host) => $this->host((string) $host), $allowed)));
        $t = $this->normalise($text);
        $label = '[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?';
        $endings = implode('|', self::ENDINGS);
        $sure = implode('|', self::SURE);
        $beforeTr = implode('|', self::BEFORE_TR);
        $edge = '(?![\p{L}\p{N}-])';

        $hits = [];
        $take = function (string $pattern, ?callable $toHost = null) use (&$hits, $t) {
            if (preg_match_all($pattern, $t, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($found as $match) {
                    $hits[] = [$match[0][1], trim($match[0][0]), $toHost ? $toHost(array_column($match, 0)) : null];
                }
            }
        };

        // http://…, https://…, also written with spaces.
        $take('~(?:https?|ftp)\s*:\s*/\s*/\s*([^\s/<>"\']+)~u', fn ($m) => $m[1]);
        // www.name…, "www . name . com" ("www name com" is caught further down)
        $take('~(?<![\p{L}\p{N}])www\s*[.,]\s*('.$label.'(?:\s*\.\s*'.$label.')*)~u', fn ($m) => preg_replace('/\s+/', '', $m[1]));
        // 185.12.34.56
        $take('~(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])~');
        // name.com, shop.name.com.tr — not "5.de" or "3.in": a name has a letter in it.
        $take('~(?<![\p{L}\p{N}.-])((?:'.$label.'\.)+)(?:'.$endings.')'.$edge.'~u', fn ($m) => preg_match('/\p{L}/u', $m[1]) ? $m[0] : '');
        // "name .com", "name . com.tr": a space before the dot is never an end of sentence.
        $take('~(?<![\p{L}\p{N}])('.$label.')\s+\.\s*((?:'.$endings.')(?:\s*\.\s*tr)?)'.$edge.'~u', fn ($m) => $m[1].'.'.preg_replace('/\s+/', '', $m[2]));
        // "name. com", "name,com", "name , com"
        $take('~(?<![\p{L}\p{N}])('.$label.')\s*[.,;:]\s*((?:'.$sure.')(?:\s*[.,]?\s*tr)?)'.$edge.'~u', fn ($m) => $m[1].'.'.preg_replace('/[\s.,]+/', '.', $m[2]));
        // "name com", "name com tr"
        $take('~(?<![\p{L}\p{N}])((?!www\b)'.$label.')\s+((?:'.$sure.')(?:\s+tr)?)'.$edge.'~u', fn ($m) => mb_strlen($m[1]) >= 3 ? $m[1].'.'.preg_replace('/\s+/', '.', $m[2]) : '');
        // "name net tr", "name.gen tr"
        $take('~(?<![\p{L}\p{N}])('.$label.')[\s.,]+('.$beforeTr.')[\s.,]+tr'.$edge.'~u', fn ($m) => $m[1].'.'.$m[2].'.tr');

        // Addresses that are fine first: a looser pattern may see a part of one ("forum.site.com" → "site.com").
        $fine = [];
        $open = [];
        foreach ($hits as [$at, $piece, $host]) {
            if ($host === '') {
                continue; // a pattern that decided it was not an address after all
            }
            $host = $host === null ? null : $this->host($host);
            if ($host !== null && (in_array($host, self::NOT_ADDRESSES, true) || $this->isAllowed($host, $allowed))) {
                $fine[] = [$at, $at + strlen($piece)];
            } else {
                $open[] = [$at, $piece];
            }
        }
        usort($open, fn ($a, $b) => $a[0] <=> $b[0]);
        foreach ($open as [$at, $piece]) {
            $end = $at + strlen($piece);
            foreach ($fine as [$from, $to]) {
                if ($at >= $from && $end <= $to) {
                    continue 2;
                }
            }

            return $piece;
        }

        return null;
    }

    /** Lower case, with the usual disguises of a dot undone. */
    protected function normalise(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text; // full-width and other look-alike forms
        }
        $t = mb_strtolower(str_replace(['İ', 'I'], ['i', 'i'], $text));
        $t = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{00AD}]/u', '', $t);          // invisible characters
        $t = preg_replace('/[。．｡•·∙●▪]/u', '.', $t);                                           // other dots
        $t = preg_replace('/\s*[(\[{<]\s*(?:dot|nokta|\.)\s*[)\]}>]\s*/u', '.', $t);           // (dot) [nokta] [.]
        $t = preg_replace('/(?<=[\p{L}\p{N}])\s+(?:dot|nokta)\s+(?=[\p{L}\p{N}])/u', '.', $t);   // "site nokta com"
        // Letters typed one by one: "s i t e . c o m"
        $t = preg_replace_callback(
            '/(?<![\p{L}\p{N}])(?:[\p{L}\p{N}.][ \t]+){3,}[\p{L}\p{N}](?![\p{L}\p{N}])/u',
            fn ($m) => preg_replace('/\s+/', '', $m[0]),
            $t,
        );

        return $t;
    }

    protected function host(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('~^[a-z]+://~', '', $value);
        $value = preg_replace('~[/?#:].*$~', '', $value);

        return trim(preg_replace('/^www\./', '', $value), '.');
    }

    protected function isAllowed(string $host, array $allowed): bool
    {
        foreach ($allowed as $ok) {
            if ($host === $ok || str_ends_with($host, '.'.$ok)) {
                return true;
            }
        }

        return false;
    }
}
