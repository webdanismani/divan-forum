<?php

namespace App\Forum;

use App\Forum\BbCode\Renderer;
use App\Forum\Moderation\Censor;
use App\Forum\Permissions\PermissionResolver;
use App\Models\ModeratorLog;
use App\Models\Node;
use App\Models\Post;
use App\Models\PostEdit;
use App\Models\PostReaction;
use App\Models\Reaction;
use App\Models\Thread;
use App\Models\User;
use App\Notifications\ForumAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All write operations on threads and posts live here so that counters,
 * "last post" caches, alerts and the moderator log stay consistent.
 */
class Posting
{
    public function __construct(
        protected Renderer $renderer,
        protected PermissionResolver $permissions,
    ) {}

    public function createThread(Node $node, User $user, string $title, string $body, ?int $prefixId = null, ?string $ip = null): Thread
    {
        $state = $this->initialState($node, $user, $body, $title);
        $title = app(Censor::class)->clean($title);

        $thread = DB::transaction(function () use ($node, $user, $title, $body, $prefixId, $ip, $state) {
            $thread = Thread::create([
                'node_id' => $node->id,
                'user_id' => $user->id,
                'prefix_id' => $prefixId,
                'title' => $title,
                'state' => $state,
                'last_post_at' => now(),
                'last_post_user_id' => $user->id,
            ]);

            $post = $thread->posts()->create([
                'user_id' => $user->id,
                'body' => $body,
                'body_html' => $this->renderer->render($body, $user->id),
                'ip_address' => $ip,
            ]);

            $thread->update(['first_post_id' => $post->id, 'last_post_id' => $post->id]);
            DB::table('thread_watches')->insertOrIgnore(['user_id' => $user->id, 'thread_id' => $thread->id, 'created_at' => now()]);

            return $thread;
        });

        if ($state === 'visible') {
            $this->rebuildNode($node);
            $this->rebuildUser($user);
            $this->notifyMentions($thread->firstPost, $thread, $user);
            $this->notifyForumWatchers($thread, $user);
            event('forum.thread.created', [$thread]);
        }

        return $thread;
    }

    public function reply(Thread $thread, User $user, string $body, ?string $ip = null): Post
    {
        $state = $thread->state === 'visible' ? $this->initialState($thread->node, $user, $body) : 'visible';

        $post = $thread->posts()->create([
            'user_id' => $user->id,
            'body' => $body,
            'body_html' => $this->renderer->render($body, $user->id),
            'state' => $state,
            'ip_address' => $ip,
        ]);

        if ($state === 'visible') {
            $this->rebuildThread($thread);
            $this->rebuildNode($thread->node);
            $this->rebuildUser($user);
            // Nothing goes out about a thread that is still waiting for approval or has been removed.
            if ($thread->state === 'visible') {
                $this->notifyWatchers($post, $thread, $user);
                $this->notifyMentions($post, $thread, $user);
                event('forum.post.created', [$post]);
            }
        }

        return $post;
    }

    public function editPost(Post $post, User $editor, string $body): void
    {
        if ($body !== $post->body) {
            PostEdit::create(['post_id' => $post->id, 'user_id' => $editor->id, 'old_body' => $post->body]);
        }

        $post->update([
            'body' => $body,
            // An edit by a moderator still shows the author's own uploads.
            'body_html' => $this->renderer->render($body, $post->user_id),
            'edited_at' => now(),
            'edited_by' => $editor->id,
            'edit_count' => $post->edit_count + 1,
        ]);
        event('forum.post.edited', [$post]);

        if ($post->user_id !== $editor->id) {
            $this->log($editor, 'post.edit', 'post', $post->id, $post->thread->title);
        }
    }

    /** Change the state of a post (visible|moderated|deleted) or remove it for good. */
    public function setPostState(Post $post, string $state, User $actor, bool $hard = false): void
    {
        $thread = $post->thread;

        if ($hard) {
            app(Attachments::class)->deleteForPosts([$post->id]);
            $post->delete();
        } else {
            $post->update(['state' => $state]);
        }

        $this->rebuildThread($thread);
        $this->rebuildNode($thread->node);
        if ($post->user) {
            $this->rebuildUser($post->user);
        }

        if ($post->user_id !== $actor->id || $hard) {
            $this->log($actor, $hard ? 'post.hardDelete' : 'post.'.$state, 'post', $post->id, $thread->title);
        }
    }

    public function setThreadState(Thread $thread, string $state, User $actor, bool $hard = false): void
    {
        $authors = User::whereIn('id', $thread->posts()->distinct()->limit(500)->pluck('user_id')->filter())->get();
        $wasModerated = $thread->state === 'moderated';

        if ($hard) {
            app(Attachments::class)->deleteForPosts($thread->posts()->pluck('id')->all());
            $thread->delete();
        } else {
            $thread->update(['state' => $state]);
            $this->rebuildThread($thread);
        }

        $this->rebuildNode($thread->node);
        $authors->each(fn (User $author) => $this->rebuildUser($author));

        if ($wasModerated && $state === 'visible' && ! $hard && $thread->firstPost && $thread->user) {
            $this->notifyMentions($thread->firstPost, $thread, $thread->user);
        }

        if ($thread->user_id !== $actor->id || $hard) {
            $this->log($actor, $hard ? 'thread.hardDelete' : 'thread.'.$state, 'thread', $thread->id, $thread->title);
        }
    }

    public function moveThread(Thread $thread, Node $target, User $actor): void
    {
        $source = $thread->node;
        $thread->update(['node_id' => $target->id]);
        $thread->setRelation('node', $target);

        $this->rebuildNode($source);
        $this->rebuildNode($target);
        $this->log($actor, 'thread.move', 'thread', $thread->id, $thread->title.' → '.$target->title);
    }

    public function toggle(Thread $thread, string $flag, bool $value, User $actor): void
    {
        $thread->update([$flag => $value]);
        $action = match ($flag) {
            'is_locked' => $value ? 'thread.lock' : 'thread.unlock',
            'is_sticky' => $value ? 'thread.stick' : 'thread.unstick',
        };
        $this->log($actor, $action, 'thread', $thread->id, $thread->title);
    }

    /** Add, change or (when the same reaction is sent again) remove a reaction. */
    public function react(Post $post, User $user, Reaction $reaction): void
    {
        $existing = PostReaction::where('post_id', $post->id)->where('user_id', $user->id)->first();

        if ($existing && $existing->reaction_id === $reaction->id) {
            $existing->delete();
        } elseif ($existing) {
            $existing->update(['reaction_id' => $reaction->id]);
        } else {
            PostReaction::create(['post_id' => $post->id, 'user_id' => $user->id, 'reaction_id' => $reaction->id]);

            if ($post->user && $post->user_id !== $user->id) {
                $post->user->notify(new ForumAlert('reaction', $user, route('posts.show', $post->id), [
                    'thread' => $post->thread->title,
                    'reaction' => $reaction->emoji,
                ]));
            }
        }

        $post->update(['reaction_score' => $this->scoreQuery()->where('post_reactions.post_id', $post->id)->sum('reactions.score')]);

        if ($post->user) {
            $post->user->update(['reaction_score' => $this->scoreQuery()
                ->join('posts', 'posts.id', '=', 'post_reactions.post_id')
                ->where('posts.user_id', $post->user_id)
                ->sum('reactions.score')]);
        }
    }

    protected function scoreQuery()
    {
        return DB::table('post_reactions')->join('reactions', 'reactions.id', '=', 'post_reactions.reaction_id');
    }

    // ---------------------------------------------------------------------
    // Merging, splitting and copying
    // ---------------------------------------------------------------------

    /**
     * Put the posts of several threads into the oldest one. Posts keep their
     * ids, so the merged thread reads in the order things were written.
     *
     * @param  Collection<int, Thread>  $threads
     */
    public function mergeThreads(Collection $threads, User $actor): Thread
    {
        $threads = $threads->sortBy('id')->values();
        $target = $threads->first();
        $sources = $threads->slice(1);

        DB::transaction(function () use ($target, $sources) {
            foreach ($sources as $source) {
                Post::where('thread_id', $source->id)->update(['thread_id' => $target->id]);

                foreach (DB::table('thread_watches')->where('thread_id', $source->id)->pluck('user_id') as $userId) {
                    DB::table('thread_watches')->insertOrIgnore(['user_id' => $userId, 'thread_id' => $target->id, 'created_at' => now()]);
                }

                $target->increment('view_count', $source->view_count);
                $source->delete();
            }
        });

        $this->rebuildThread($target);
        $threads->pluck('node_id')->unique()->each(fn ($nodeId) => $this->rebuildNode(Node::find($nodeId)));
        $this->rebuildAuthors($target);
        $this->log($actor, 'thread.merge', 'thread', $target->id, $target->title.' ← '.$sources->pluck('title')->implode(', '));

        return $target;
    }

    /**
     * Move posts into another thread. A thread that is left without posts is removed.
     *
     * @param  Collection<int, Post>  $posts  posts of one thread
     */
    public function movePosts(Collection $posts, Thread $target, User $actor): void
    {
        $source = $posts->first()->thread;

        Post::whereIn('id', $posts->pluck('id'))->update(['thread_id' => $target->id]);

        if ($source->posts()->exists()) {
            $this->rebuildThread($source);
        } else {
            $source->delete();
        }

        $this->rebuildThread($target);
        $this->rebuildNode($source->node);
        if ($target->node_id !== $source->node_id) {
            $this->rebuildNode($target->node);
        }
        $this->rebuildAuthors($target);
        $this->log($actor, 'post.move', 'thread', $target->id, $source->title.' → '.$target->title.' ('.$posts->count().')');
    }

    /**
     * Start a new thread from some posts of an existing one.
     *
     * @param  Collection<int, Post>  $posts
     */
    public function splitPosts(Collection $posts, Node $node, string $title, User $actor): Thread
    {
        $first = $posts->sortBy('id')->first();

        $thread = Thread::create([
            'node_id' => $node->id,
            'user_id' => $first->user_id,
            'title' => $title,
            'state' => 'visible',
            'last_post_at' => $first->created_at,
            'last_post_user_id' => $first->user_id,
            'created_at' => $first->created_at,
        ]);
        $thread->setRelation('node', $node);

        $this->movePosts($posts, $thread, $actor);

        return $thread;
    }

    /** A copy of the thread (text only, without reactions and attachments) in another forum. */
    public function copyThread(Thread $thread, Node $target, User $actor): Thread
    {
        $copy = DB::transaction(function () use ($thread, $target) {
            $copy = $thread->replicate(['view_count', 'first_post_id', 'last_post_id', 'promoted_until', 'highlight_until']);
            $copy->node_id = $target->id;
            $copy->created_at = $thread->created_at;
            $copy->save();

            foreach ($thread->posts()->orderBy('id')->get() as $post) {
                $new = $post->replicate(['reaction_score']);
                $new->thread_id = $copy->id;
                $new->created_at = $post->created_at;
                $new->save();
            }

            return $copy;
        });

        $copy->setRelation('node', $target);
        $this->rebuildThread($copy);
        $this->rebuildNode($target);
        $this->rebuildAuthors($copy);
        $this->log($actor, 'thread.copy', 'thread', $copy->id, $thread->title.' → '.$target->title);

        return $copy;
    }

    protected function rebuildAuthors(Thread $thread): void
    {
        User::whereIn('id', $thread->posts()->distinct()->limit(500)->pluck('user_id')->filter())
            ->get()
            ->each(fn (User $author) => $this->rebuildUser($author));
    }

    // ---------------------------------------------------------------------
    // Counter rebuilds
    // ---------------------------------------------------------------------

    public function rebuildThread(Thread $thread): void
    {
        $visible = $thread->posts()->where('state', 'visible');
        $last = (clone $visible)->latest('id')->first();
        $first = $thread->posts()->oldest('id')->first();

        if (! $first) {
            return;
        }

        $thread->update([
            'first_post_id' => $first->id,
            'reply_count' => max(0, (clone $visible)->count() - ($first->state === 'visible' ? 1 : 0)),
            'last_post_id' => $last?->id ?? $first->id,
            'last_post_user_id' => $last?->user_id ?? $first->user_id,
            'last_post_at' => $last?->created_at ?? $first->created_at,
        ]);
    }

    public function rebuildNode(Node $node): void
    {
        $threads = Thread::where('node_id', $node->id)->where('state', 'visible');
        $last = (clone $threads)->orderByDesc('last_post_at')->first();
        $count = (clone $threads)->count();

        $node->update([
            'thread_count' => $count,
            'post_count' => $count + (int) (clone $threads)->sum('reply_count'),
            'last_thread_id' => $last?->id,
            'last_post_user_id' => $last?->last_post_user_id,
            'last_post_at' => $last?->last_post_at,
        ]);

        app(NodeTree::class)->flush();
    }

    public function rebuildUser(User $user): void
    {
        $user->update([
            'thread_count' => Thread::where('user_id', $user->id)->where('state', 'visible')->count(),
            'post_count' => Post::where('posts.user_id', $user->id)
                ->where('posts.state', 'visible')
                ->whereHas('thread', fn ($q) => $q->where('state', 'visible'))
                ->count(),
        ]);
    }

    // ---------------------------------------------------------------------

    /**
     * What happens to a message this member wants to post here: "visible" or "moderated" (a moderator looks first).
     * Add-ons take part through the filter "post.verdict": they get the verdict so far and what is being posted,
     * and return "visible", "moderated" or ['refuse' => 'why'] — the last one stops the message with that text.
     * Members who may approve posts themselves are never screened.
     *
     * This is the check for an edit: only add-ons are asked. The forum's own reasons to hold a message back
     * (a forum that approves everything, links from new members) apply when it is first posted — a member who
     * fixes a typo in an approved post must not lose it to the queue again.
     *
     * @throws ValidationException when an add-on refuses the message
     */
    public function screen(Node $node, User $user, string $body, string $title = ''): string
    {
        return $this->initialState($node, $user, $body, $title, false);
    }

    protected function initialState(Node $node, User $user, string $body = '', string $title = '', bool $forumRules = true): string
    {
        if ($this->permissions->can($user, 'mod.approve', $node)) {
            return 'visible';
        }

        // Links from members who have hardly posted are the usual shape of spam: a moderator looks first.
        $minimum = (int) setting('spam.link_min_posts');
        $hasLink = $minimum > 0 && $user->post_count < $minimum && preg_match('/https?:\/\/|www\.|\[url|\[img|\[media/i', $body);

        $state = $forumRules && ($node->require_approval || $hasLink) ? 'moderated' : 'visible';

        $verdict = app(Addons\Hooks::class)->filter('post.verdict', $state, ['node' => $node, 'user' => $user, 'body' => $body, 'title' => $title]);
        if (is_array($verdict) && filled($verdict['refuse'] ?? null)) {
            throw ValidationException::withMessages(['body' => (string) $verdict['refuse']]);
        }

        // An add-on can ask for a look by a moderator; it cannot wave through what the forum itself holds back.
        return $state === 'moderated' || $verdict === 'moderated' ? 'moderated' : 'visible';
    }

    protected function notifyWatchers(Post $post, Thread $thread, User $author): void
    {
        $watchers = User::whereIn('id', DB::table('thread_watches')->where('thread_id', $thread->id)->pluck('user_id'))
            ->where('id', '!=', $author->id)
            ->get();

        foreach ($watchers as $watcher) {
            // One unread alert per thread is enough until the member comes back.
            $pending = $watcher->unreadNotifications()
                ->where('data', 'like', '%"kind":"reply"%')
                ->where('data', 'like', '%"thread_id":'.$thread->id.'}%')
                ->exists();

            if (! $pending && $this->permissions->can($watcher, 'node.view', $thread->node)) {
                $watcher->notify(new ForumAlert('reply', $author, route('posts.show', $post->id), [
                    'thread' => $thread->title,
                    'thread_id' => $thread->id,
                ]));
            }
        }
    }

    /** Mark a reply as the answer to a question thread, or clear the mark with null. */
    public function setSolution(Thread $thread, ?Post $post, User $actor): void
    {
        $previous = $thread->solution_post_id ? Post::find($thread->solution_post_id) : null;
        $thread->update(['solution_post_id' => $post?->id]);

        foreach (array_filter([$previous?->user, $post?->user]) as $author) {
            $author->update(['solution_count' => Thread::where('state', 'visible')
                ->whereIn('solution_post_id', Post::where('user_id', $author->id)->select('id'))
                ->count()]);
        }

        if ($post && $post->user && $post->user_id !== $actor->id) {
            $post->user->notify(new ForumAlert('solution', $actor, route('posts.show', $post->id), ['thread' => $thread->title]));
        }
    }

    protected function notifyForumWatchers(Thread $thread, User $author): void
    {
        $watchers = User::whereIn('id', DB::table('node_watches')->where('node_id', $thread->node_id)->limit(1000)->pluck('user_id'))
            ->where('id', '!=', $author->id)
            ->get();

        foreach ($watchers as $watcher) {
            if ($this->permissions->can($watcher, 'node.view', $thread->node)) {
                $watcher->notify(new ForumAlert('new_thread', $author, route('threads.show', $thread), [
                    'thread' => $thread->title,
                    'forum' => $thread->node->title,
                ]));
            }
        }
    }

    protected function notifyMentions(Post $post, Thread $thread, User $author): void
    {
        foreach ($this->renderer->mentionedUsers($post->body) as $mentioned) {
            if ($mentioned->id !== $author->id && $this->permissions->can($mentioned, 'node.view', $thread->node)) {
                $mentioned->notify(new ForumAlert('mention', $author, route('posts.show', $post->id), [
                    'thread' => $thread->title,
                ]));
            }
        }
    }

    public function log(User $actor, string $action, ?string $type = null, ?int $id = null, ?string $summary = null): void
    {
        ModeratorLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'content_type' => $type,
            'content_id' => $id,
            'summary' => $summary ? mb_substr($summary, 0, 250) : null,
            'ip_address' => request()->ip(),
        ]);
    }
}
