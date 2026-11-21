<?php

namespace App\Forum;

use App\Forum\Permissions\PermissionResolver;
use App\Models\Badge;
use App\Models\GroupPromotion;
use App\Models\User;
use App\Models\UserTitle;
use App\Notifications\ForumAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/** What members earn by taking part: titles by post count, badges and automatic group promotions. */
class Rewards
{
    protected ?array $ladder = null;

    /** @var array<int, array{color: ?string, icon: ?string}>|null the look of each step, by its post count */
    protected ?array $designs = null;

    protected ?Collection $badges = null;

    /** Title for a post count, from the ladder configured in the admin panel. */
    public function titleFor(int $posts): ?string
    {
        if ($this->ladder === null) {
            try {
                $steps = UserTitle::orderByDesc('min_posts')->get();
                $this->ladder = $steps->pluck('title', 'min_posts')->all();
                $this->designs = $steps->mapWithKeys(fn (UserTitle $step) => [(int) $step->min_posts => ['color' => $step->color, 'icon' => $step->icon]])->all();
            } catch (Throwable) {
                $this->ladder = [];
                $this->designs = [];
            }
        }

        foreach ($this->ladder as $minimum => $title) {
            if ($posts >= $minimum) {
                return $title;
            }
        }

        return null;
    }

    /**
     * Where a post count stands on the title ladder: the title, the step reached, the number of steps, and the step after it.
     *
     * @return array{title: string, level: int, total: int, next: ?array{title: string, posts: int}, color: ?string, icon: ?string}|null
     */
    public function rank(int $posts): ?array
    {
        if ($this->titleFor($posts) === null) {
            return null;
        }
        $steps = array_reverse($this->ladder, true); // lowest first
        $level = 0;
        $title = '';
        $next = null;
        $design = ['color' => null, 'icon' => null];
        foreach ($steps as $minimum => $name) {
            if ($posts >= $minimum) {
                $level++;
                $title = $name;
                $design = $this->designs[(int) $minimum] ?? $design;
            } elseif ($next === null) {
                $next = ['title' => $name, 'posts' => (int) $minimum];
            }
        }

        return ['title' => $title, 'level' => $level, 'total' => count($steps), 'next' => $next] + $design;
    }

    public function flush(): void
    {
        $this->ladder = null;
        $this->designs = null;
        $this->badges = null;
    }

    /** @return Collection<int, Badge> badges of one member, in display order */
    public function badgesOf(User $user): Collection
    {
        $this->badges ??= Badge::where('is_active', true)->orderBy('display_order')->get()->keyBy('id');

        return DB::table('user_badges')->where('user_id', $user->id)->pluck('badge_id')
            ->map(fn ($id) => $this->badges->get($id))->filter()->sortBy('display_order')->values();
    }

    public function award(User $user, Badge $badge): bool
    {
        if (! DB::table('user_badges')->insertOrIgnore(['user_id' => $user->id, 'badge_id' => $badge->id, 'created_at' => now()])) {
            return false;
        }

        $user->notify(new ForumAlert('badge', null, route('members.show', $user), ['title' => $badge->title]));

        return true;
    }

    /** Scheduled: give automatic badges to everybody who has earned them since the last run. */
    public function awardBadges(): int
    {
        $given = 0;

        foreach (Badge::where('is_active', true)->where('criteria', '!=', 'manual')->get() as $badge) {
            $query = User::query()->where('is_banned', false)
                ->whereNotIn('id', DB::table('user_badges')->where('badge_id', $badge->id)->select('user_id'));

            match ($badge->criteria) {
                'posts' => $query->where('post_count', '>=', $badge->threshold),
                'reactions' => $query->where('reaction_score', '>=', $badge->threshold),
                'solutions' => $query->where('solution_count', '>=', $badge->threshold),
                'days' => $query->where('created_at', '<=', now()->subDays($badge->threshold)),
                default => $query->whereRaw('1 = 0'),
            };

            foreach ($query->limit(300)->get() as $user) {
                $given += $this->award($user, $badge) ? 1 : 0;
            }
        }

        return $given;
    }

    /** Scheduled: add members to a group once they meet every condition of a promotion. */
    public function promote(): int
    {
        $promoted = 0;

        foreach (GroupPromotion::where('is_active', true)->get() as $promotion) {
            $members = User::query()->where('is_banned', false)
                ->where('post_count', '>=', $promotion->min_posts)
                ->where('reaction_score', '>=', $promotion->min_reaction_score)
                ->where('created_at', '<=', now()->subDays($promotion->min_days))
                ->where('user_group_id', '!=', $promotion->user_group_id)
                ->whereNotIn('id', DB::table('user_group_user')->where('user_group_id', $promotion->user_group_id)->select('user_id'))
                ->limit(300)->get();

            foreach ($members as $member) {
                $member->groups()->syncWithoutDetaching([$promotion->user_group_id]);
                $promoted++;
            }
        }

        if ($promoted > 0) {
            app(PermissionResolver::class)->flush();
        }

        return $promoted;
    }
}
