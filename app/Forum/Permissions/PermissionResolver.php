<?php

namespace App\Forum\Permissions;

use App\Forum\NodeTree;
use App\Models\Node;
use App\Models\NodePermission;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Group based permissions with per-node overrides.
 *
 * Every permission is "allow", "never" or not set. A member belongs to a
 * primary group plus any number of secondary groups; guests use the guest
 * group. Resolution order for a node scoped check:
 *   1. the node itself, then each ancestor - the first level where any of
 *      the member's groups has an explicit value decides (never beats allow)
 *   2. the global group permissions (never beats allow)
 *   3. otherwise denied
 * Members of the "admin" system group are always allowed.
 */
class PermissionResolver
{
    /** What a member cannot do before the e-mail address is confirmed (when the board requires it). */
    protected const NEEDS_VERIFIED_EMAIL = ['thread.create', 'post.create', 'post.react', 'post.report', 'conversation.start', 'attachment.upload', 'profile.post', 'shoutbox.post'];

    /** What somebody listed as moderator of a forum may do there and in its sub-forums. */
    public const FORUM_MODERATOR = ['mod.editAny', 'mod.deleteAny', 'mod.viewDeleted', 'mod.approve', 'mod.lock', 'mod.stick', 'mod.move', 'mod.merge'];

    /** @var array<int, int[]>|null user id => node ids */
    protected ?array $moderators = null;

    protected ?Collection $groups = null;

    /** @var array<int, array<int, array<string, string>>>|null [node_id][group_id] => permissions */
    protected ?array $nodePermissions = null;

    /** @var array<int, int[]> */
    protected array $groupIdsByUser = [];

    protected array $decisions = [];

    public function __construct(protected NodeTree $tree) {}

    public function groups(): Collection
    {
        return $this->groups ??= UserGroup::all()->keyBy('id');
    }

    /** @return int[] */
    public function groupIdsFor(?User $user): array
    {
        if (! $user || $user->isBanned()) {
            $guest = $this->groups()->firstWhere('system_key', 'guest');

            return $guest ? [$guest->id] : [];
        }

        return $this->groupIdsByUser[$user->id] ??= array_values(array_unique(array_merge(
            [(int) $user->user_group_id],
            $user->groups->pluck('id')->map(fn ($id) => (int) $id)->all(),
        )));
    }

    public function isAdmin(?User $user): bool
    {
        if (! $user || $user->isBanned()) {
            return false;
        }

        $groups = $this->groups();
        foreach ($this->groupIdsFor($user) as $id) {
            if ($groups->get($id)?->system_key === 'admin') {
                return true;
            }
        }

        return false;
    }

    public function can(?User $user, string $permission, Node|int|null $node = null): bool
    {
        $nodeId = $node instanceof Node ? $node->id : $node;
        $key = ($user?->id ?? 0).'|'.$permission.'|'.($nodeId ?? 0);

        return $this->decisions[$key] ??= $this->resolve($user, $permission, $nodeId);
    }

    protected function resolve(?User $user, string $permission, ?int $nodeId): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if ($user && ! $user->isVerified() && in_array($permission, self::NEEDS_VERIFIED_EMAIL, true) && setting('registration.verify_email')) {
            return false;
        }

        if ($user && $nodeId && in_array($permission, self::FORUM_MODERATOR, true) && ! $user->isBanned() && $this->moderates($user->id, $nodeId)) {
            return true;
        }

        $groupIds = $this->groupIdsFor($user);

        // 1. node level, walking up the tree
        $guard = 0;
        $current = $this->tree->get($nodeId);
        while ($current && $guard++ < 50) {
            $entries = $this->nodePermissions()[$current->id] ?? [];
            $decision = $this->decide(array_map(fn (int $gid) => $entries[$gid][$permission] ?? null, $groupIds));
            if ($decision !== null) {
                return $decision;
            }
            $current = $this->tree->get($current->parent_id);
        }

        // 2. global group permissions
        $groups = $this->groups();

        return $this->decide(array_map(
            fn (int $gid) => $groups->get($gid)?->permissions[$permission] ?? null,
            $groupIds,
        )) ?? false;
    }

    /** Is the member listed as moderator of this forum or one of its parents? */
    public function moderates(int $userId, int $nodeId): bool
    {
        if ($this->moderators === null) {
            $this->moderators = [];
            try {
                foreach (DB::table('node_moderators')->get() as $row) {
                    $this->moderators[(int) $row->user_id][] = (int) $row->node_id;
                }
            } catch (Throwable) {
                // table not migrated yet
            }
        }

        $own = $this->moderators[$userId] ?? [];
        $guard = 0;
        $current = $own ? $this->tree->get($nodeId) : null;
        while ($current && $guard++ < 50) {
            if (in_array($current->id, $own, true)) {
                return true;
            }
            $current = $this->tree->get($current->parent_id);
        }

        return false;
    }

    /** @param array<int, string|null> $values */
    protected function decide(array $values): ?bool
    {
        if (in_array('never', $values, true)) {
            return false;
        }

        return in_array('allow', $values, true) ? true : null;
    }

    protected function nodePermissions(): array
    {
        if ($this->nodePermissions === null) {
            $this->nodePermissions = [];
            foreach (NodePermission::all() as $entry) {
                $this->nodePermissions[$entry->node_id][$entry->user_group_id] = $entry->permissions ?? [];
            }
        }

        return $this->nodePermissions;
    }

    public function flush(): void
    {
        $this->groups = null;
        $this->moderators = null;
        $this->nodePermissions = null;
        $this->groupIdsByUser = [];
        $this->decisions = [];
    }
}
