<?php

namespace App\Forum;

use App\Models\User;
use App\Notifications\ForumAlert;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;

/** Relations between members: who ignores and who follows whom. */
class Community
{
    /** @var array<int, int[]> */
    protected array $ignored = [];

    /** @return int[] ids of the members this member ignores */
    public function ignoredIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return $this->ignored[$user->id] ??= DB::table('user_ignores')->where('user_id', $user->id)->pluck('ignored_id')->map(fn ($id) => (int) $id)->all();
    }

    public function ignores(?User $user, ?int $otherId): bool
    {
        return $otherId !== null && in_array($otherId, $this->ignoredIds($user), true);
    }

    /** @return bool true when the member is ignored now */
    public function toggleIgnore(User $user, User $other): bool
    {
        unset($this->ignored[$user->id]);
        $key = ['user_id' => $user->id, 'ignored_id' => $other->id];

        if (DB::table('user_ignores')->where($key)->delete()) {
            return false;
        }

        DB::table('user_ignores')->insert($key + ['created_at' => now()]);
        // Ignoring somebody also stops following them.
        if (DB::table('user_follows')->where(['user_id' => $user->id, 'followed_id' => $other->id])->delete()) {
            $this->recount($other);
        }

        return true;
    }

    public function follows(?User $user, int $otherId): bool
    {
        return $user !== null && DB::table('user_follows')->where(['user_id' => $user->id, 'followed_id' => $otherId])->exists();
    }

    /** @return bool true when the member is followed now */
    public function toggleFollow(User $user, User $other): bool
    {
        $key = ['user_id' => $user->id, 'followed_id' => $other->id];
        $following = ! DB::table('user_follows')->where($key)->delete();

        if ($following) {
            DB::table('user_follows')->insert($key + ['created_at' => now()]);
            $other->notify(new ForumAlert('follow', $user, route('members.show', $user)));
        }
        $this->recount($other);

        return $following;
    }

    /** Listener: members never get alerts caused by somebody they ignore. */
    public function filterAlert(NotificationSending $event): bool
    {
        if ($event->notification instanceof ForumAlert && $event->notifiable instanceof User && $event->notification->actor) {
            return ! $this->ignores($event->notifiable, $event->notification->actor->id);
        }

        return true;
    }

    protected function recount(User $user): void
    {
        $user->forceFill(['follower_count' => DB::table('user_follows')->where('followed_id', $user->id)->count()])->saveQuietly();
    }
}
