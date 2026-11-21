<?php

namespace App\Forum\Integrations;

use App\Forum\BbCode\Renderer;
use App\Jobs\DeliverWebhook;
use App\Models\Post;
use App\Models\Thread;
use App\Models\User;
use App\Models\Webhook;
use Throwable;

/** Tells other systems about what happens on the forum: one signed JSON request per event. */
class Webhooks
{
    public const EVENTS = ['thread.created' => 'New thread', 'post.created' => 'New reply', 'user.registered' => 'New member'];

    /** Queue a delivery for every active webhook that wants this event. */
    public function dispatch(string $event, array|callable $data): void
    {
        try {
            $hooks = Webhook::where('is_active', true)->get()->filter(fn (Webhook $hook) => in_array($event, $hook->events ?? [], true));
        } catch (Throwable) {
            return; // table not migrated yet
        }

        if ($hooks->isEmpty()) {
            return;
        }

        $payload = $this->payload($event, is_callable($data) ? $data($this) : $data);
        foreach ($hooks as $hook) {
            DeliverWebhook::dispatch($hook->id, $payload);
        }
    }

    public function payload(string $event, array $data): array
    {
        return ['event' => $event, 'occurred_at' => now()->toAtomString(), 'forum' => ['name' => (string) setting('board.name'), 'url' => route('forum.index')], 'data' => $data];
    }

    public function threadData(Thread $thread): array
    {
        return [
            'id' => $thread->id,
            'title' => $thread->title,
            'url' => route('threads.show', $thread),
            'forum' => ['id' => $thread->node_id, 'title' => (string) $thread->node?->title],
            'author' => $this->userData($thread->user),
            'text' => app(Renderer::class)->plain((string) $thread->firstPost?->body, 500),
        ];
    }

    public function postData(Post $post): array
    {
        return [
            'id' => $post->id,
            'url' => route('posts.show', $post->id),
            'thread' => ['id' => $post->thread_id, 'title' => (string) $post->thread?->title],
            'author' => $this->userData($post->user),
            'text' => app(Renderer::class)->plain($post->body, 500),
        ];
    }

    /** Never the e-mail address or anything else that is not public on the profile. */
    public function userData(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'username' => $user->username, 'url' => route('members.show', $user)] : null;
    }

    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }
}
