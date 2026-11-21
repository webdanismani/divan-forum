<?php

namespace App\Http\Controllers\Concerns;

use App\Forum\NodeTree;
use App\Models\Node;
use App\Models\Post;
use App\Models\Thread;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;

trait ChecksVisibility
{
    /** The node if the visitor may see it; guests are sent to login, members get a 403. */
    protected function viewableNode(?int $nodeId): Node
    {
        $node = app(NodeTree::class)->viewable(auth()->user())->get($nodeId);

        if (! $node) {
            if (auth()->guest()) {
                throw new AuthenticationException;
            }
            abort(app(NodeTree::class)->get($nodeId) ? 403 : 404);
        }

        return $node;
    }

    protected function visibleThreadNode(Thread $thread): Node
    {
        $node = $this->viewableNode($thread->node_id);
        abort_unless($this->stateVisible($thread->state, $thread->user_id, $node), 404);
        $thread->setRelation('node', $node);

        return $node;
    }

    /** @return array{0: Thread, 1: Node} */
    protected function visiblePost(Post $post): array
    {
        $thread = $post->thread;
        $node = $this->visibleThreadNode($thread);
        abort_unless($this->stateVisible($post->state, $post->user_id, $node), 404);

        return [$thread, $node];
    }

    protected function stateVisible(string $state, ?int $ownerId, Node $node): bool
    {
        return match ($state) {
            'visible' => true,
            'moderated' => forum_can('mod.approve', $node) || ($ownerId !== null && $ownerId === auth()->id()),
            'deleted' => forum_can('mod.viewDeleted', $node),
            default => false,
        };
    }

    protected function checkFlood(): void
    {
        $seconds = (int) setting('posting.flood_seconds');
        $user = auth()->user();

        if ($seconds > 0 && ! $user->group?->is_staff) {
            $last = Post::where('user_id', $user->id)->latest('id')->value('created_at');
            if ($last && now()->diffInSeconds($last, true) < $seconds) {
                throw ValidationException::withMessages([
                    'body' => __('You must wait at least :seconds seconds between posts.', ['seconds' => $seconds]),
                ]);
            }
        }
    }
}
