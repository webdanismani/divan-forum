<?php

namespace App\Forum\BbCode;

use App\Forum\Addons\Hooks;
use App\Forum\Moderation\Censor;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Safe BBCode to HTML renderer.
 *
 * The whole input is HTML-escaped first; afterwards only whitelisted tags
 * are turned into markup, and every attribute value is validated by a strict
 * pattern, so user input can never introduce raw HTML or script URLs.
 */
class Renderer
{
    protected const URL = 'https?:\/\/[^\s\[\]<>"\'\x00]+';

    protected const MENTION = '/(?<![\p{L}\p{N}_@.\/])@([\p{L}\p{N}_-]{3,30})/u';

    /** Whose uploads [attach] may show; set for the length of one render() call. */
    protected ?int $author = null;

    /**
     * @param  int|null  $authorId  the member the text belongs to (default: whoever is signed in). Only that
     *                              member's uploads are placed with [attach]: numbers of other people's files show nothing.
     */
    public function render(string $text, ?int $authorId = null): string
    {
        $this->author = $authorId ?? auth()->id();
        $text = str_replace(["\r\n", "\r", "\x00"], ["\n", "\n", ''], trim($text));
        $text = app(Censor::class)->clean($text);
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Code is taken out first so nothing inside it gets parsed.
        $stash = [];
        $hold = function (string $markup) use (&$stash): string {
            $key = "\x00".count($stash)."\x00";
            $stash[$key] = $markup;

            return $key;
        };
        $html = preg_replace_callback('/\[code\](.*?)\[\/code\]/is', fn ($m) => $hold('<pre class="bb-code"><code>'.trim($m[1], "\n").'</code></pre>'), $html);
        $html = preg_replace_callback('/\[icode\](.*?)\[\/icode\]/is', fn ($m) => $hold('<code class="bb-icode">'.$m[1].'</code>'), $html);

        $html = $this->linkMentions($html);
        $html = $this->embeds($html, $hold);

        // Bare URLs (not the ones written inside [url] / [img] tags).
        $html = preg_replace_callback('/(?<=^|[\s(])('.self::URL.')/im', function ($m) {
            $url = rtrim($m[1], '.,;:!?)');
            $rest = substr($m[1], strlen($url));

            return $this->anchor($url, $url).$rest;
        }, $html);

        $simple = [
            'b' => ['<strong>', '</strong>'],
            'i' => ['<em>', '</em>'],
            'u' => ['<u>', '</u>'],
            's' => ['<s>', '</s>'],
            'center' => ['<div class="bb-center">', '</div>'],
            'right' => ['<div class="bb-right">', '</div>'],
            'spoiler' => ['<details class="bb-spoiler"><summary>Spoiler</summary><div>', '</div></details>'],
        ];
        foreach ($simple as $tag => [$open, $close]) {
            $html = $this->replaceNested('/\['.$tag.'\]((?:(?!\['.$tag.'\]).)*?)\[\/'.$tag.'\]/is', $open.'$1'.$close, $html);
        }

        $html = $this->replaceNested('/\[color=(#[0-9a-f]{3}(?:[0-9a-f]{3})?|[a-z]{3,20})\]((?:(?!\[color=).)*?)\[\/color\]/is', '<span style="color:$1">$2</span>', $html);
        $html = $this->replaceNested('/\[size=([1-7])\]((?:(?!\[size=).)*?)\[\/size\]/is', '<span class="bb-size-$1">$2</span>', $html);

        $html = preg_replace_callback('/\[url\]('.self::URL.')\[\/url\]/i', fn ($m) => $this->anchor($m[1], $m[1]), $html);
        $html = preg_replace_callback('/\[url=('.self::URL.')\](.+?)\[\/url\]/is', fn ($m) => $this->anchor($m[1], $m[2]), $html);
        $html = preg_replace('/\[img\]('.self::URL.')\[\/img\]/i', '<img class="bb-img" src="$1" alt="" loading="lazy">', $html);
        $html = $this->attachments($html);
        // Add-ons may add tags of their own ($hold keeps finished markup away from the remaining rules).
        $html = app(Hooks::class)->filter('bbcode.render', $html, $hold);

        $html = preg_replace_callback('/\[list(=1)?\](.*?)\[\/list\]/is', function ($m) {
            $items = array_filter(array_map('trim', explode('[*]', $m[2])), fn ($item) => $item !== '');
            $tag = $m[1] ? 'ol' : 'ul';

            return "<$tag class=\"bb-list\"><li>".implode('</li><li>', $items)."</li></$tag>";
        }, $html);

        // Quotes, innermost first so nesting works.
        $quote = '/\[quote(?:=(?:&quot;)?(.*?)(?:&quot;)?)?\]((?:(?!\[quote[=\]]).)*?)\[\/quote\]/is';
        $html = $this->replaceNested($quote, function ($m) {
            $header = '';
            if (($m[1] ?? '') !== '') {
                $name = trim(preg_replace('/,\s*post:\s*\d+\s*$/i', '', $m[1]));
                $label = $name.' '.htmlspecialchars(__('said:'));
                if (preg_match('/post:\s*(\d+)\s*$/i', $m[1], $p)) {
                    $label = '<a href="'.route('posts.show', (int) $p[1]).'">'.$label.' ↑</a>';
                }
                $header = '<div class="bb-quote-head">'.$label.'</div>';
            }

            return '<blockquote class="bb-quote">'.$header.'<div class="bb-quote-body">'.trim($m[2]).'</div></blockquote>';
        }, $html);

        // Line breaks, but not around block level elements.
        $html = preg_replace('/\n?(<\/?(?:blockquote|ul|ol|li|div|details|summary)[^>]*>)\n?/', '$1', $html);
        $html = preg_replace('/\n?(\x00\d+\x00)\n/', '$1', $html);
        $html = nl2br($html, false);

        return strtr($this->balance($html), $stash);
    }

    /**
     * Closes what was left open and drops closing tags without an opening one. BBCode tags written across each
     * other ([spoiler][quote]…[/spoiler]…[/quote]) would otherwise end the post's own container and take the
     * rest of the page with it. Everything in angle brackets here is markup made above: the text itself is escaped.
     */
    protected function balance(string $html): string
    {
        $void = ['br' => 1, 'img' => 1, 'hr' => 1, 'wbr' => 1, 'input' => 1, 'source' => 1];
        $open = [];
        $out = '';

        foreach (preg_split('/(<\/?[a-zA-Z][a-zA-Z0-9]*\b[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $part) {
            if ($i % 2 === 0) {
                $out .= $part;

                continue;
            }

            preg_match('/^<(\/?)([a-zA-Z][a-zA-Z0-9]*)/', $part, $m);
            $name = strtolower($m[2]);
            if (isset($void[$name]) || str_ends_with($part, '/>')) {
                $out .= $part;
            } elseif ($m[1] === '') {
                $open[] = $name;
                $out .= $part;
            } elseif (($at = array_search($name, array_reverse($open, true), true)) !== false) {
                // Close whatever was opened inside it first, then the tag itself.
                while (count($open) - 1 > $at) {
                    $out .= '</'.array_pop($open).'>';
                }
                array_pop($open);
                $out .= $part;
            }
        }
        while ($open) {
            $out .= '</'.array_pop($open).'>';
        }

        return $out;
    }

    /** Plain text without markup, e.g. for snippets and meta descriptions. */
    public function plain(string $text, int $limit = 200): string
    {
        $text = app(Hooks::class)->filter('bbcode.plain', $text);
        $text = preg_replace('/\[quote.*?\].*?\[\/quote\]/is', ' ', $text);
        $text = preg_replace('/\[attach\]\d+\[\/attach\]/i', ' ', $text);
        $text = preg_replace('/\[\/?[a-z*]+(?:=[^\]]*)?\]/i', ' ', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit)).'…' : $text;
    }

    /** Members mentioned with @username in the raw text. @return Collection<int, User> */
    public function mentionedUsers(string $text): Collection
    {
        $text = preg_replace('/\[(code|icode|quote)\b.*?\].*?\[\/\1\]/is', ' ', $text);
        preg_match_all(self::MENTION, $text, $matches);
        $names = array_slice(array_unique($matches[1]), 0, 10);

        return $names ? User::whereIn('username', $names)->get() : collect();
    }

    protected function linkMentions(string $html): string
    {
        preg_match_all(self::MENTION, $html, $matches);
        if (! $matches[1]) {
            return $html;
        }

        $users = User::whereIn('username', array_slice(array_unique($matches[1]), 0, 10))->get()->keyBy('username');

        return preg_replace_callback(self::MENTION, function ($m) use ($users) {
            $user = $users->get($m[1]);

            return $user
                ? '<a class="bb-mention" href="'.route('members.show', $user).'">@'.$m[1].'</a>'
                : $m[0];
        }, $html);
    }

    /**
     * Videos and posts from known sites: [media]address[/media], or an address
     * alone on a line. Only ids that match a strict pattern reach the markup.
     */
    protected function embeds(string $html, callable $hold): string
    {
        $html = preg_replace_callback('/\[media\]('.self::URL.')\[\/media\]/i', fn ($m) => $this->embed($m[1], $hold) ?? $this->anchor($m[1], $m[1]), $html);

        if (! setting('posting.media_embeds', true)) {
            return $html;
        }

        return preg_replace_callback('/^[ \t]*('.self::URL.')[ \t]*$/m', fn ($m) => $this->embed($m[1], $hold) ?? $m[0], $html);
    }

    protected function embed(string $url, callable $hold): ?string
    {
        $frame = fn (string $src, string $title) => $hold('<div class="bb-embed bb-video"><iframe src="'.$src.'" title="'.$title.'" loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture"></iframe></div>');

        if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^#\s]*?&amp;)?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
            return $frame('https://www.youtube-nocookie.com/embed/'.$m[1], 'YouTube');
        }
        if (preg_match('~^https?://(?:www\.)?vimeo\.com/(\d{6,12})~', $url, $m)) {
            return $frame('https://player.vimeo.com/video/'.$m[1], 'Vimeo');
        }
        if (preg_match('~^https?://(?:www\.)?dailymotion\.com/video/([a-z0-9]{5,12})~i', $url, $m)) {
            return $frame('https://www.dailymotion.com/embed/video/'.$m[1], 'Dailymotion');
        }
        if (preg_match('~^https?://(?:www\.|mobile\.)?(?:twitter|x)\.com/([A-Za-z0-9_]{1,15})/status/(\d{1,25})~', $url, $m)) {
            $link = 'https://twitter.com/'.$m[1].'/status/'.$m[2];

            return $hold('<blockquote class="twitter-tweet bb-embed" data-embed="x"><a href="'.$link.'" rel="nofollow ugc noopener" target="_blank">'.$link.'</a></blockquote>');
        }
        if (preg_match('~^https?://(?:www\.)?instagram\.com/(p|reel|tv)/([A-Za-z0-9_-]{5,20})~', $url, $m)) {
            $link = 'https://www.instagram.com/'.$m[1].'/'.$m[2].'/';

            return $hold('<blockquote class="instagram-media bb-embed" data-embed="instagram" data-instgrm-permalink="'.$link.'" data-instgrm-version="14"><a href="'.$link.'" rel="nofollow ugc noopener" target="_blank">'.$link.'</a></blockquote>');
        }

        return null;
    }

    /** Uploaded files placed in the text. Who may open them is decided when the file is requested. */
    protected function attachments(string $html): string
    {
        $pattern = '/\[attach\](\d+)\[\/attach\]/i';
        if (! preg_match_all($pattern, $html, $matches)) {
            return $html;
        }

        $files = Attachment::whereIn('id', array_slice(array_unique($matches[1]), 0, 50))->get()->keyBy('id');

        return preg_replace_callback($pattern, function ($m) use ($files) {
            $file = $files->get((int) $m[1]);
            if (! $file || $this->author === null || (int) $file->user_id !== $this->author) {
                return '';
            }
            $name = htmlspecialchars($file->filename, ENT_QUOTES, 'UTF-8');

            return $file->is_image
                ? '<a class="bb-attach-img" href="'.e($file->url()).'" target="_blank"><img class="bb-img" src="'.e($file->url(true)).'" alt="'.$name.'" loading="lazy"></a>'
                : '<a class="bb-attach" href="'.e($file->url()).'">'.$name.' ('.$file->humanSize().')</a>';
        }, $html);
    }

    protected function anchor(string $url, string $label): string
    {
        return '<a href="'.$url.'" target="_blank" rel="nofollow ugc noopener">'.$label.'</a>';
    }

    protected function replaceNested(string $pattern, string|callable $replacement, string $html): string
    {
        $guard = 0;
        do {
            $html = is_callable($replacement)
                ? preg_replace_callback($pattern, $replacement, $html, -1, $count)
                : preg_replace($pattern, $replacement, $html, -1, $count);
        } while ($count > 0 && $guard++ < 20);

        return $html;
    }
}
