<?php

namespace App\Forum;

use App\Models\NavLink;
use App\Models\Notice;
use App\Models\Page;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** What the administrator added around the forum: notices, extra menu links and pages. */
class Site
{
    protected const CACHE_KEY = 'forum.site';

    protected ?array $data = null;

    /** @return Collection<int, Notice> notices this visitor should see now */
    public function notices(?User $user): Collection
    {
        $now = now();

        return collect($this->data()['notices'])
            ->filter(fn (Notice $notice) => ($notice->starts_at === null || $notice->starts_at <= $now)
                && ($notice->ends_at === null || $notice->ends_at > $now)
                && ($notice->audience === 'all' || ($notice->audience === 'guests') === ($user === null)))
            ->values();
    }

    /** @return Collection<int, NavLink> */
    public function links(string $position): Collection
    {
        return collect($this->data()['links'])->where('position', $position)->values();
    }

    /** @return Collection<int, Page> published pages listed in the footer */
    public function footerPages(): Collection
    {
        return collect($this->data()['pages']);
    }

    /**
     * What the foot of the page shows: the board in numbers and its busiest forums.
     * Counts that need a query are kept for a moment; they do not have to be exact to the second.
     *
     * @return array{threads: int, posts: int, members: int, online: int, newest: ?User, forums: Collection}
     */
    public function footer(?User $user): array
    {
        $tree = app(NodeTree::class);
        $visible = $tree->viewable($user);
        // Only plain values go into the cache: the cache does not give objects back.
        $newest = Cache::remember('forum.footer.newest_member', 300, fn () => User::where('is_banned', false)->latest('id')->first(['id', 'username'])?->only(['id', 'username']));

        return [
            'threads' => (int) $tree->all()->sum('thread_count'),
            'posts' => (int) $tree->all()->sum('post_count'),
            'members' => (int) Cache::remember('forum.footer.members', 300, fn () => User::count()),
            'online' => (int) Cache::remember('forum.footer.online', 60, fn () => User::online()->count()),
            'newest' => $newest ? (new User)->forceFill($newest) : null,
            'forums' => $visible->where('type', 'forum')->where('post_count', '>', 0)->sortByDesc('post_count')->take(5)->values(),
        ];
    }

    public function flush(): void
    {
        $this->data = null;
        Cache::forget(self::CACHE_KEY);
    }

    protected function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        try {
            return $this->data = Cache::rememberForever(self::CACHE_KEY, fn () => [
                'notices' => Notice::where('is_active', true)->orderBy('display_order')->get()->all(),
                'links' => NavLink::where('is_active', true)->orderBy('display_order')->get()->all(),
                'pages' => Page::where('is_published', true)->where('show_in_footer', true)->orderBy('display_order')->get(['id', 'slug', 'title'])->all(),
            ]);
        } catch (Throwable) {
            return $this->data = ['notices' => [], 'links' => [], 'pages' => []]; // tables not migrated yet
        }
    }
}
