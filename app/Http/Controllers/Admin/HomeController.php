<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Home;
use App\Forum\NodeTree;
use App\Forum\Settings;
use App\Forum\Themes;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Sections of the home page: which ones are shown and what they say. */
class HomeController extends Controller
{
    public function __construct(protected Home $home, protected Settings $settings) {}

    public function edit(NodeTree $tree, Themes $themes)
    {
        $themeId = $themes->activeId();

        return view('admin.home', [
            'home' => $this->home,
            'slides' => $this->home->slides(),
            'tiles' => $this->home->tiles(),
            'promos' => $this->home->promos(),
            'news' => $this->home->newsOptions(),
            'social' => $this->home->social(),
            'forums' => array_filter($tree->flat(), fn (array $item) => ! $item['node']->isLink()),
            // The theme in use, when it was designed for a certain set of sections.
            'designedFor' => $themeId && is_array($themes->option('home')) ? $themes->all()[$themeId] : null,
        ]);
    }

    public function update(Request $request)
    {
        $text = fn (int $max) => ['nullable', 'string', 'max:'.$max];
        $picture = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'];

        $data = $request->validate([
            'sections' => ['nullable', 'array'],
            'slides' => ['nullable', 'array', 'max:'.Home::MAX_SLIDES],
            'slides.*.badge' => $text(80), 'slides.*.title' => $text(120), 'slides.*.mark' => $text(60), 'slides.*.text' => $text(300),
            'slides.*.b1_label' => $text(40), 'slides.*.b1_link' => $text(300), 'slides.*.b2_label' => $text(40), 'slides.*.b2_link' => $text(300),
            'slide_image.*' => $picture,
            'tiles' => ['nullable', 'array', 'max:'.Home::MAX_TILES],
            'tiles.*.icon' => ['nullable', Rule::in(Home::ICONS)], 'tiles.*.title' => $text(40), 'tiles.*.text' => $text(40), 'tiles.*.link' => $text(300),
            'promos' => ['nullable', 'array', 'max:'.Home::MAX_PROMOS],
            'promos.*.title' => $text(60), 'promos.*.text' => $text(200), 'promos.*.button' => $text(40), 'promos.*.link' => $text(300),
            'promos.*.style' => ['nullable', Rule::in(array_keys(Home::PROMO_STYLES))],
            'promo_image.*' => $picture,
            'news.node_id' => ['nullable', 'integer', Rule::exists('nodes', 'id')],
            'news.title' => $text(80),
            'news.count' => ['nullable', 'integer', 'min:2', 'max:8'],
            'social' => ['nullable', 'array'],
            'social.*' => ['nullable', 'url:http,https', 'max:300'],
        ]);

        $this->home->saveSections((array) ($data['sections'] ?? []));

        $this->settings->set([
            'home.slides' => $this->rows($request, 'slides', 'slide_image', $this->home->slides(), ['badge', 'title', 'mark', 'text', 'b1_label', 'b1_link', 'b2_label', 'b2_link']),
            'home.tiles' => array_values(array_filter(array_map(fn ($row) => [
                'icon' => (string) ($row['icon'] ?? ''), 'title' => trim((string) ($row['title'] ?? '')),
                'text' => trim((string) ($row['text'] ?? '')), 'link' => trim((string) ($row['link'] ?? '')),
            ], (array) ($data['tiles'] ?? [])), fn ($row) => $row['title'] !== '')),
            'home.promos' => $this->rows($request, 'promos', 'promo_image', $this->home->promos(), ['title', 'text', 'button', 'link', 'style']),
            'home.news' => [
                'node_id' => (int) ($data['news']['node_id'] ?? 0),
                'title' => trim((string) ($data['news']['title'] ?? '')),
                'count' => (int) ($data['news']['count'] ?? 4),
            ],
            'home.social' => array_filter(array_intersect_key(array_map(fn ($url) => trim((string) $url), (array) ($data['social'] ?? [])), Home::NETWORKS)),
        ]);

        return back()->with('success', __('The home page has been saved.'));
    }

    /** Takes over the sections the theme in use was designed for. Texts already written stay. */
    public function adopt(Themes $themes)
    {
        $id = $themes->activeId();
        if (! $id || ! $this->home->adopt($themes->all()[$id])) {
            return back()->with('error', __('The theme in use does not come with a home page layout.'));
        }

        return back()->with('success', __('The home page now uses the sections this theme was designed for.'));
    }

    /**
     * Rows of a list that can carry a picture. A new upload replaces the old picture; ticking "remove" drops it;
     * otherwise the row keeps the picture it had (an upload of ours or a file of the theme).
     *
     * @param  array<int, array<string, string>>  $before
     */
    protected function rows(Request $request, string $key, string $fileKey, array $before, array $fields): array
    {
        $kept = array_column($before, 'image');
        $rows = [];
        $used = [];

        foreach ((array) $request->input($key, []) as $i => $row) {
            $item = [];
            foreach ($fields as $field) {
                $item[$field] = trim((string) ($row[$field] ?? ''));
            }
            $image = in_array($row['image'] ?? '', $kept, true) ? (string) $row['image'] : '';
            if ($request->hasFile($fileKey.'.'.$i)) {
                $image = $request->file($fileKey.'.'.$i)->store('home', 'public');
            } elseif (! empty($row['remove_image'])) {
                $image = '';
            }
            $item['image'] = $image;
            if ($item['title'] !== '') {
                $rows[] = $item;
                $used[] = $image;
            }
        }

        // Uploaded pictures no row points at any more are deleted.
        foreach ($kept as $old) {
            if ($old !== '' && ! str_starts_with($old, 'theme:') && ! in_array($old, $used, true)) {
                Storage::disk('public')->delete($old);
            }
        }

        return $rows;
    }
}
