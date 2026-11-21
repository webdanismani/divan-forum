<?php

namespace App\Forum;

use App\Models\Attachment;
use App\Models\Post;
use App\Models\Thread;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Sections of the home page an administrator can switch on and fill: a hero
 * with slides, quick tiles, news cards, side boxes, a band of figures and an
 * e-mail box in the footer. With none of the new sections on, the home page
 * is the classic board index. Everything lives in the settings table; a theme
 * may bring starting texts (theme.json "home"), which only ever fill slots
 * the administrator left empty.
 */
class Home
{
    /** Section => [label, on by default]. The first group is new, the second is what the classic index always showed. */
    public const SECTIONS = [
        'hero' => ['Hero with slides at the top of the home page', false],
        'tiles' => ['Quick tiles under the hero', false],
        'news' => ['News cards: the newest threads of one forum', false],
        'online' => ['Side box: members online, with avatars', false],
        'promos' => ['Side box: promotion boxes', false],
        'popular' => ['Side box: popular threads', false],
        'social' => ['Social media links (side box and footer)', false],
        'band' => ['Band with the board in figures', false],
        'signup' => ['E-mail box in the footer that leads visitors to registration', false],
        'latest' => ['Tabs with the latest replies, newest and popular threads', true],
        'account' => ['Side box: member card or the invitation to join', true],
        'lists' => ['Side boxes: today, top members, newest members', true],
        'statistics' => ['Statistics block under the forum list', true],
    ];

    public const MAX_SLIDES = 5;

    public const MAX_TILES = 6;

    public const MAX_PROMOS = 3;

    /** Icons offered for quick tiles and for forums. */
    public const ICONS = ['gamepad', 'trophy', 'book', 'download', 'users', 'message', 'monitor', 'phone', 'star', 'flame', 'bolt', 'tag', 'shield', 'heart', 'megaphone', 'cart', 'image', 'code', 'calendar', 'crown', 'gem', 'award', 'folder', 'chart', 'key', 'wallet'];

    public const PROMO_STYLES = ['gold' => 'Dark with a golden button', 'blue' => 'Dark with an accent button', 'plain' => 'Plain box'];

    /** Network => [name, brand colour, glyph (Simple Icons, CC0)]. */
    public const NETWORKS = [
        'youtube' => ['YouTube', '#ff0000', 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z'],
        'discord' => ['Discord', '#5865f2', 'M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189Z'],
        'x' => ['X', '#000000', 'M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z'],
        'instagram' => ['Instagram', '#e4405f', 'M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077'],
        'telegram' => ['Telegram', '#26a5e4', 'M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z'],
        'facebook' => ['Facebook', '#0866ff', 'M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z'],
        'tiktok' => ['TikTok', '#000000', 'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z'],
        'twitch' => ['Twitch', '#9146ff', 'M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714Z'],
    ];

    public function __construct(protected Settings $settings) {}

    // ---- Reading ------------------------------------------------------------------------

    public function enabled(string $section): bool
    {
        $stored = (array) $this->settings->get('home.sections');

        return (bool) ($stored[$section] ?? (self::SECTIONS[$section][1] ?? false));
    }

    /** True when at least one of the new sections is on: the page then needs their markup. */
    public function any(): bool
    {
        foreach (self::SECTIONS as $key => [, $default]) {
            if (! $default && $this->enabled($key)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array<string, string>> */
    public function slides(): array
    {
        return $this->rows('home.slides', ['badge', 'title', 'mark', 'text', 'b1_label', 'b1_link', 'b2_label', 'b2_link', 'image'], 'title');
    }

    /** @return array<int, array<string, string>> */
    public function tiles(): array
    {
        return $this->rows('home.tiles', ['icon', 'title', 'text', 'link'], 'title');
    }

    /** @return array<int, array<string, string>> */
    public function promos(): array
    {
        return $this->rows('home.promos', ['title', 'text', 'button', 'link', 'style', 'image'], 'title');
    }

    /** @return array<string, string> network => address, only the ones filled in */
    public function social(): array
    {
        $stored = (array) $this->settings->get('home.social');
        $links = [];
        foreach (array_keys(self::NETWORKS) as $network) {
            $url = trim((string) ($stored[$network] ?? ''));
            if (preg_match('~^https?://~i', $url)) {
                $links[$network] = $url;
            }
        }

        return $links;
    }

    /** @return array{node_id: int, title: string, count: int} */
    public function newsOptions(): array
    {
        $stored = (array) $this->settings->get('home.news');

        return [
            'node_id' => (int) ($stored['node_id'] ?? 0),
            'title' => trim((string) ($stored['title'] ?? '')),
            'count' => max(2, min(8, (int) ($stored['count'] ?? 4))),
        ];
    }

    /**
     * The newest threads of the news forum (and what is below it) that this visitor may see.
     *
     * @param  Collection<int, \App\Models\Node>  $viewable
     * @return Collection<int, Thread> each with ->home_picture (address or null)
     */
    public function news(Collection $viewable): Collection
    {
        $options = $this->newsOptions();
        $tree = app(NodeTree::class);
        $node = $tree->get($options['node_id']);
        $ids = $node ? array_values(array_intersect($tree->descendantIds($node), $viewable->keys()->all())) : $viewable->keys()->all();

        $threads = Thread::query()->whereIn('node_id', $ids)->where('state', 'visible')
            ->with(['prefix', 'user'])->orderByDesc('id')->limit($options['count'])->get();

        return $this->withPictures($threads);
    }

    /** Adds ->home_picture to every thread: the first picture of its opening post, if it has one. */
    public function withPictures(Collection $threads): Collection
    {
        foreach ($threads as $thread) {
            $picture = Cache::remember('forum.home.picture.'.$thread->id, 600, fn () => $this->pictureOf($thread));
            $thread->setAttribute('home_picture', $picture !== '' ? $picture : null);
        }

        return $threads;
    }

    /** Address for a link an administrator typed: a path on the forum, an anchor or a web address; anything else is dropped. */
    public function link(?string $link): string
    {
        $link = trim((string) $link);
        if ($link === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $link) || str_starts_with($link, '#')) {
            return $link;
        }

        return str_starts_with($link, '/') && ! str_starts_with($link, '//') ? url($link) : '';
    }

    /** Address of a stored picture: an upload, or a file the active theme brought ("theme:Vendor/Name/file"). */
    public function image(?string $image): ?string
    {
        $image = (string) $image;
        if ($image === '') {
            return null;
        }
        if (str_starts_with($image, 'theme:')) {
            [$id, $file] = array_pad(explode('|', str_replace('theme:', '', $image), 2), 2, '');
            $themes = app(Themes::class);

            return $themes->asset($id, $file) ? url('theme-assets/'.$id.'/'.$file).'?v='.rawurlencode((string) ($themes->all()[$id]['version'] ?? '')) : null;
        }

        return Storage::disk('public')->url($image);
    }

    /** 12480 → "12.5K": figures on the home page are meant to be read at a glance. */
    public static function short(int|float|null $value): string
    {
        $value = (float) $value;
        foreach ([[1_000_000, 'M'], [1_000, 'K']] as [$step, $unit]) {
            if ($value >= $step) {
                $text = number_format($value / $step, 1, '.', '');

                return (str_ends_with($text, '.0') ? substr($text, 0, -2) : $text).$unit;
            }
        }

        return (string) (int) $value;
    }

    /** Escaped headline with the highlighted word wrapped in <em> and "|" turned into a line break. */
    public static function headline(string $title, string $mark): string
    {
        $html = e($title);
        $mark = e(trim($mark));
        if ($mark !== '' && ($at = mb_stripos($html, $mark)) !== false) {
            $html = mb_substr($html, 0, $at).'<em>'.mb_substr($html, $at, mb_strlen($mark)).'</em>'.mb_substr($html, $at + mb_strlen($mark));
        }

        return str_replace('|', '<br>', $html);
    }

    // ---- Writing ------------------------------------------------------------------------

    /** @param array<string, mixed> $sections section => on/off */
    public function saveSections(array $sections): void
    {
        $clean = [];
        foreach (array_keys(self::SECTIONS) as $key) {
            $clean[$key] = ! empty($sections[$key]);
        }
        $this->settings->set(['home.sections' => $clean]);
    }

    /**
     * Takes over what a theme was designed for: its sections are switched as it names them, and its
     * starting texts fill the lists the administrator has not filled yet. Nothing already written is replaced.
     *
     * @param  array<string, mixed>  $manifest  the theme's manifest
     */
    public function adopt(array $manifest): bool
    {
        $home = $manifest['home'] ?? null;
        $id = (string) ($manifest['id'] ?? '');
        if (! is_array($home) || $id === '') {
            return false;
        }

        $values = ['home.adopted' => $id];

        // What the home page looked like before this theme: switching the theme off brings it back.
        $returning = null;
        if ($this->adopted() !== $id) {
            $before = [];
            foreach (array_keys(self::SECTIONS) as $key) {
                $before[$key] = $this->enabled($key);
            }
            $values['home.before'] = $before;

            // The same theme coming back: its sections are as the administrator left them last time.
            $left = $this->settings->get('home.left');
            $returning = is_array($left) && ($left['id'] ?? null) === $id && is_array($left['sections'] ?? null) ? $left['sections'] : null;
        }

        if ($returning !== null) {
            $sections = [];
            foreach (self::SECTIONS as $key => [, $default]) {
                $sections[$key] = (bool) ($returning[$key] ?? $default);
            }
            $values['home.sections'] = $sections;
        } elseif (is_array($home['sections'] ?? null)) {
            $sections = [];
            foreach (array_keys(self::SECTIONS) as $key) {
                $sections[$key] = array_key_exists($key, $home['sections']) ? (bool) $home['sections'][$key] : $this->enabled($key);
            }
            $values['home.sections'] = $sections;
        }

        $board = (string) $this->settings->get('board.name');
        $fill = function (mixed $rows, array $fields, int $max) use ($board, $id): array {
            $clean = [];
            foreach (array_slice(is_array($rows) ? $rows : [], 0, $max) as $row) {
                $item = [];
                foreach ($fields as $field) {
                    $value = is_array($row) && is_scalar($row[$field] ?? null) ? mb_substr(trim((string) $row[$field]), 0, 300) : '';
                    $item[$field] = str_replace('{board}', $board, $value);
                }
                // A picture can only be one of the theme's own files.
                if (isset($item['image'])) {
                    $item['image'] = Themes::validAsset($item['image']) ? 'theme:'.$id.'|'.$item['image'] : '';
                }
                $clean[] = $item;
            }

            return $clean;
        };

        if (! $this->slides() && ($slides = $fill($home['slides'] ?? null, ['badge', 'title', 'mark', 'text', 'b1_label', 'b1_link', 'b2_label', 'b2_link', 'image'], self::MAX_SLIDES))) {
            $values['home.slides'] = $slides;
        }
        if (! $this->tiles() && ($tiles = $fill($home['tiles'] ?? null, ['icon', 'title', 'text', 'link'], self::MAX_TILES))) {
            $values['home.tiles'] = $tiles;
        }
        if (! $this->promos() && ($promos = $fill($home['promos'] ?? null, ['title', 'text', 'button', 'link', 'style', 'image'], self::MAX_PROMOS))) {
            $values['home.promos'] = $promos;
        }
        if ($this->newsOptions()['title'] === '' && is_scalar($home['news_title'] ?? null)) {
            $values['home.news'] = ['title' => mb_substr(trim((string) $home['news_title']), 0, 80)] + $this->newsOptions();
        }

        $this->settings->set($values);

        return true;
    }

    /**
     * The theme is switched off or replaced: the sections it switched go back to what they were before it
     * (to the classic index, if that was never recorded). Texts and pictures the administrator may have
     * edited stay stored, so switching the theme on again shows them as they were left.
     */
    public function release(string $id): bool
    {
        if ($this->adopted() !== $id) {
            return false;
        }

        $before = $this->settings->get('home.before');
        $sections = [];
        $left = [];
        foreach (self::SECTIONS as $key => [, $default]) {
            $left[$key] = $this->enabled($key);
            $sections[$key] = is_array($before) && array_key_exists($key, $before) ? (bool) $before[$key] : $default;
        }
        $this->settings->set([
            'home.sections' => $sections,
            'home.adopted' => null,
            'home.before' => null,
            'home.left' => ['id' => $id, 'sections' => $left],
        ]);

        return true;
    }

    /** The theme whose home page layout was taken over last (so that switching it on again changes nothing). */
    public function adopted(): ?string
    {
        $id = $this->settings->get('home.adopted');

        return is_string($id) ? $id : null;
    }

    // ---- Inside -------------------------------------------------------------------------

    /** @return array<int, array<string, string>> rows of a stored list, without the ones whose main field is empty */
    protected function rows(string $key, array $fields, string $required): array
    {
        $rows = [];
        foreach ((array) $this->settings->get($key) as $row) {
            if (! is_array($row) || trim((string) ($row[$required] ?? '')) === '') {
                continue;
            }
            $rows[] = array_map(fn (string $field) => trim((string) ($row[$field] ?? '')), array_combine($fields, $fields));
        }

        return $rows;
    }

    protected function pictureOf(Thread $thread): string
    {
        if (! $thread->first_post_id) {
            return '';
        }
        $attachment = Attachment::where('post_id', $thread->first_post_id)->where('is_image', true)->orderBy('id')->first();
        if ($attachment) {
            // The address is checked against the forum permissions when the file is asked for.
            return $attachment->url(true);
        }
        // Taken from the text as other members may quote it, not from the stored markup: what an add-on keeps
        // back from readers (hidden content) must not turn up as the picture of a news card either.
        $post = Post::find($thread->first_post_id);
        $source = (string) app(\App\Forum\Addons\Hooks::class)->filter('post.source', (string) $post?->body, $post);

        return preg_match('~\[img\](https?://[^\s\[\]<>"\'\x00]+)\[/img\]~i', $source, $match) ? $match[1] : '';
    }
}
