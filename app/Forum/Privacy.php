<?php

namespace App\Forum;

use App\Forum\Permissions\PermissionResolver;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Friends and each member's privacy choices: who may see the profile, write
 * on it, start a conversation, see the online state, send a friend request.
 *
 * Which of these a member may set at all is a group permission, so a board
 * can keep some of them for paid groups. A choice the member is no longer
 * allowed to make is simply not applied.
 */
class Privacy
{
    public const LEVELS = ['everyone' => 'Everybody', 'members' => 'Members', 'friends' => 'Friends only', 'nobody' => 'Nobody'];

    /** What applies when a member chose nothing (or may not choose). */
    public const DEFAULTS = ['profile' => 'everyone', 'wall' => 'members', 'messages' => 'members', 'online' => true, 'requests' => true];

    /** Permission needed to change each choice. */
    public const NEEDS = ['profile' => 'privacy.profile', 'wall' => 'privacy.contact', 'messages' => 'privacy.contact', 'online' => 'privacy.online', 'requests' => 'friends.use'];

    /** @var array<int, int[]> */
    protected array $friends = [];

    public function __construct(protected PermissionResolver $permissions) {}

    // ---- Choices ------------------------------------------------------------------------------

    public function choice(User $member, string $key): mixed
    {
        $stored = json_decode((string) $member->privacy, true);
        $value = is_array($stored) ? ($stored[$key] ?? null) : null;

        if ($value === null || ! $this->permissions->can($member, self::NEEDS[$key])) {
            return self::DEFAULTS[$key];
        }

        return is_bool(self::DEFAULTS[$key]) ? (bool) $value : (isset(self::LEVELS[$value]) ? $value : self::DEFAULTS[$key]);
    }

    /** The choices a member may make, with their current values (for the settings form). */
    public function editable(User $member): array
    {
        return collect(self::NEEDS)->filter(fn (string $permission) => $this->permissions->can($member, $permission))
            ->map(fn ($permission, string $key) => $this->choice($member, $key))->all();
    }

    public function save(User $member, array $input): void
    {
        $stored = json_decode((string) $member->privacy, true) ?: [];
        foreach (array_keys($this->editable($member)) as $key) {
            $stored[$key] = is_bool(self::DEFAULTS[$key])
                ? ! empty($input[$key])
                : (isset(self::LEVELS[$input[$key] ?? '']) ? $input[$key] : self::DEFAULTS[$key]);
        }
        $member->forceFill(['privacy' => json_encode($stored)])->save();
    }

    // ---- Rules --------------------------------------------------------------------------------

    protected function allows(string $level, ?User $viewer, User $member): bool
    {
        // The member themselves and the staff are never locked out.
        if ($viewer && ($viewer->id === $member->id || $viewer->group?->is_staff || $viewer->isAdmin())) {
            return true;
        }

        return match ($level) {
            'everyone' => true,
            'members' => $viewer !== null,
            'friends' => $viewer !== null && $this->areFriends($viewer, $member),
            default => false,
        };
    }

    public function canViewProfile(?User $viewer, User $member): bool
    {
        return $this->allows($this->choice($member, 'profile'), $viewer, $member);
    }

    public function canWriteOnWall(?User $viewer, User $member): bool
    {
        return $this->canViewProfile($viewer, $member) && $this->allows($this->choice($member, 'wall'), $viewer, $member);
    }

    public function canMessage(?User $viewer, User $member): bool
    {
        return $this->allows($this->choice($member, 'messages'), $viewer, $member);
    }

    public function showsOnline(?User $viewer, User $member): bool
    {
        return $this->choice($member, 'online') || $this->allows('nobody', $viewer, $member);
    }

    // ---- Friends ------------------------------------------------------------------------------

    /** @return int[] ids of accepted friends */
    public function friendIds(User $user): array
    {
        return $this->friends[$user->id] ??= DB::table('friendships')->where('status', 'accepted')
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('friend_id', $user->id))
            ->get(['user_id', 'friend_id'])
            ->map(fn ($row) => (int) ((int) $row->user_id === $user->id ? $row->friend_id : $row->user_id))->all();
    }

    public function areFriends(User $a, User $b): bool
    {
        return in_array($b->id, $this->friendIds($a), true);
    }

    /** none | friends | sent (the viewer asked) | received (the viewer was asked) */
    public function status(?User $viewer, User $member): string
    {
        if (! $viewer || $viewer->id === $member->id) {
            return 'none';
        }
        $row = $this->between($viewer, $member);

        return match (true) {
            ! $row => 'none',
            $row->status === 'accepted' => 'friends',
            (int) $row->user_id === $viewer->id => 'sent',
            default => 'received',
        };
    }

    public function canRequest(User $from, User $to): bool
    {
        return $from->id !== $to->id && ! $to->isBanned()
            && $this->permissions->can($from, 'friends.use') && $this->permissions->can($to, 'friends.use')
            && $this->choice($to, 'requests');
    }

    /** Sends a request, or accepts the one that was waiting from the other side. Returns the new status. */
    public function request(User $from, User $to): string
    {
        $row = $this->between($from, $to);

        if ($row && $row->status === 'pending' && (int) $row->friend_id === $from->id) {
            DB::table('friendships')->where('id', $row->id)->update(['status' => 'accepted', 'updated_at' => now()]);
            $this->friends = [];

            return 'friends';
        }
        if (! $row) {
            DB::table('friendships')->insert(['user_id' => $from->id, 'friend_id' => $to->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

            return 'sent';
        }

        return $this->status($from, $to);
    }

    /** Withdraws a request, declines one, or ends a friendship. */
    public function remove(User $a, User $b): void
    {
        if ($row = $this->between($a, $b)) {
            DB::table('friendships')->where('id', $row->id)->delete();
            $this->friends = [];
        }
    }

    /** @return Collection<int, User> */
    public function friendsOf(User $user, int $limit = 200): Collection
    {
        return User::with('group')->whereIn('id', $this->friendIds($user))->orderBy('username')->limit($limit)->get();
    }

    /** Requests waiting for this member's answer. */
    public function incoming(User $user): Collection
    {
        return User::with('group')->whereIn('id', DB::table('friendships')->where('friend_id', $user->id)->where('status', 'pending')->select('user_id'))->orderBy('username')->get();
    }

    public function outgoing(User $user): Collection
    {
        return User::with('group')->whereIn('id', DB::table('friendships')->where('user_id', $user->id)->where('status', 'pending')->select('friend_id'))->orderBy('username')->get();
    }

    protected function between(User $a, User $b): ?object
    {
        return DB::table('friendships')
            ->where(fn ($q) => $q->where('user_id', $a->id)->where('friend_id', $b->id))
            ->orWhere(fn ($q) => $q->where('user_id', $b->id)->where('friend_id', $a->id))
            ->first();
    }
}
