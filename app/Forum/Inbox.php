<?php

namespace App\Forum;

use App\Forum\Permissions\PermissionResolver;
use App\Models\User;
use App\Notifications\ForumAlert;
use Illuminate\Support\Facades\DB;

/**
 * Inbox quota of private conversations. Every conversation a member takes part in counts;
 * leaving one frees its place. The number comes from the member's user groups — the most
 * generous one applies — and falls back to the general setting.
 */
class Inbox
{
    public function __construct(protected PermissionResolver $permissions, protected Settings $settings) {}

    /** Conversations this member may keep; 0 means there is no limit. */
    public function quota(User $user): int
    {
        $general = max(0, (int) $this->settings->get('conversations.quota'));
        $groups = $this->permissions->groups();
        $best = null;

        foreach ($this->permissions->groupIdsFor($user) as $id) {
            $own = $groups->get($id)?->conversation_quota;
            $limit = $own === null ? $general : (int) $own;
            if ($limit === 0) {
                return 0;
            }
            $best = max($best ?? 0, $limit);
        }

        return $best ?? $general;
    }

    public function used(User $user): int
    {
        return DB::table('conversation_participants')->where('user_id', $user->id)->count();
    }

    public function isFull(User $user): bool
    {
        $quota = $this->quota($user);

        return $quota > 0 && $this->used($user) >= $quota;
    }

    /** Share of the quota in use (0–100), or null when there is no limit. */
    public function percent(User $user): ?int
    {
        $quota = $this->quota($user);

        return $quota > 0 ? (int) min(100, round($this->used($user) / $quota * 100)) : null;
    }

    /** Tells a member that somebody could not reach them — once a day at most. */
    public function warnFull(User $member, ?User $sender = null): void
    {
        $recent = $member->notifications()->where('created_at', '>=', now()->subDay())->get()
            ->contains(fn ($alert) => ($alert->data['kind'] ?? '') === 'inbox_full');

        if (! $recent) {
            $member->notify(new ForumAlert('inbox_full', $sender, route('conversations.index')));
        }
    }
}
