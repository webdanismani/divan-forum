<?php

namespace App\Jobs;

use App\Forum\Ai\Assistant;
use App\Forum\Posting;
use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A new member's post is looked at by the AI after it has been published.
 * What it considers spam is taken back into the approval queue, where a
 * moderator has the last word; nothing is ever deleted here.
 */
class CheckSpam implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $postId) {}

    public function handle(Assistant $assistant, Posting $posting): void
    {
        $post = Post::with('thread.node', 'user')->find($this->postId);
        $thread = $post?->thread;

        if (! $post || ! $thread || $post->state !== 'visible' || $thread->state !== 'visible') {
            return;
        }
        if ($assistant->looksLikeSpam($thread->title, $post->body) !== true) {
            return;
        }

        if ($thread->first_post_id === $post->id) {
            $thread->update(['state' => 'moderated']);
        } else {
            $post->update(['state' => 'moderated']);
            $posting->rebuildThread($thread);
        }

        $posting->rebuildNode($thread->node);
        if ($post->user) {
            $posting->rebuildUser($post->user);
        }
    }
}
