<?php

namespace App\Forum;

use App\Forum\Permissions\PermissionResolver;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Collection;

/** In-memory copy of the (small) node tree, loaded once per request. */
class NodeTree
{
    protected ?Collection $nodes = null;

    /** @return Collection<int, Node> keyed by id, in display order */
    public function all(): Collection
    {
        return $this->nodes ??= Node::orderBy('display_order')->orderBy('id')->get()->keyBy('id');
    }

    public function get(?int $id): ?Node
    {
        return $id ? $this->all()->get($id) : null;
    }

    /** @return Collection<int, Node> */
    public function children(?int $parentId): Collection
    {
        return $this->all()->filter(fn (Node $n) => $n->parent_id === $parentId)->values();
    }

    /** Ancestors ordered root first (the node itself excluded). */
    public function ancestors(Node $node): array
    {
        $chain = [];
        $guard = 0;
        $current = $this->get($node->parent_id);
        while ($current && $guard++ < 50) {
            array_unshift($chain, $current);
            $current = $this->get($current->parent_id);
        }

        return $chain;
    }

    /** Ids of the node and everything below it. */
    public function descendantIds(Node $node): array
    {
        $ids = [$node->id];
        foreach ($this->children($node->id) as $child) {
            $ids = array_merge($ids, $this->descendantIds($child));
        }

        return $ids;
    }

    /** Nodes the user may see: active, permitted and with a visible parent chain. */
    public function viewable(?User $user): Collection
    {
        $resolver = app(PermissionResolver::class);
        $visible = [];

        $walk = function (?int $parentId) use (&$walk, &$visible, $resolver, $user) {
            foreach ($this->children($parentId) as $node) {
                if ($node->is_active && $resolver->can($user, 'node.view', $node)) {
                    $visible[$node->id] = $node;
                    $walk($node->id);
                }
            }
        };
        $walk(null);

        return collect($visible);
    }

    public function viewableIds(?User $user): array
    {
        return $this->viewable($user)->keys()->all();
    }

    /** Flat list with depth, for <select> elements. @return array<int, array{node: Node, depth: int}> */
    public function flat(?Collection $only = null): array
    {
        $out = [];
        $walk = function (?int $parentId, int $depth) use (&$walk, &$out, $only) {
            foreach ($this->children($parentId) as $node) {
                if ($only && ! $only->has($node->id)) {
                    continue;
                }
                $out[] = ['node' => $node, 'depth' => $depth];
                $walk($node->id, $depth + 1);
            }
        };
        $walk(null, 0);

        return $out;
    }

    public function flush(): void
    {
        $this->nodes = null;
    }
}
