<?php

namespace App\Http\Controllers;

use App\Forum\Addons\Hooks;
use App\Forum\Attachments;
use App\Forum\BbCode\Renderer;
use App\Forum\Moderation\Censor;
use App\Forum\NodeTree;
use App\Forum\Posting;
use App\Forum\Security\Bans;
use App\Forum\Tags;
use App\Http\Controllers\Concerns\ChecksVisibility;
use App\Models\Node;
use App\Models\Post;
use App\Models\Reaction;
use App\Models\Report;
use App\Models\Thread;
use App\Models\ThreadPrefix;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PostController extends Controller
{
    use ChecksVisibility;

    public function __construct(protected Posting $posting) {}

    /** Permalink: redirect to the right page of the thread. */
    public function show(Post $post)
    {
        [$thread, $node] = $this->visiblePost($post);

        $position = $thread->posts()->visibleIn($node)->where('id', '<=', $post->id)->count();
        $page = max(1, (int) ceil($position / (int) setting('board.posts_per_page')));

        return redirect()->to(route('threads.show', ['thread' => $thread] + ($page > 1 ? ['page' => $page] : [])).'#post-'.$post->id);
    }

    public function store(Request $request, Thread $thread)
    {
        $node = $this->visibleThreadNode($thread);
        forum_authorize('post.create', $node);
        abort_unless($thread->isVisible() || $thread->user_id === $request->user()->id, 403);
        abort_if($thread->is_locked && ! forum_can('mod.lock', $node), 403, __('This thread is locked.'));

        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:50000']]);
        $this->checkFlood();

        $post = $this->posting->reply($thread, $request->user(), $data['body'], $request->ip());
        app(Attachments::class)->attach($request->input('attachment_key'), $request->user(), $post);
        app(Bans::class)->log($request->user(), $request->ip(), 'post');

        // Sent from the thread page by script: where the post is, so the page can take it in without a reload.
        if ($request->expectsJson()) {
            $position = $thread->posts()->visibleIn($node)->where('id', '<=', $post->id)->count();
            $page = max(1, (int) ceil($position / (int) setting('board.posts_per_page')));

            return response()->json([
                'id' => $post->id,
                'visible' => $post->isVisible(),
                'url' => route('threads.show', ['thread' => $thread] + ($page > 1 ? ['page' => $page] : [])),
                'message' => $post->isVisible() ? __('Your reply has been posted.') : __('Your reply has been submitted and will be visible after approval.'),
            ]);
        }

        return redirect()->route('posts.show', $post->id)->with(
            $post->isVisible() ? 'success' : 'info',
            $post->isVisible() ? __('Your reply has been posted.') : __('Your reply has been submitted and will be visible after approval.'),
        );
    }

    public function edit(Post $post)
    {
        [$thread, $node] = $this->visiblePost($post);
        abort_unless($this->canEdit($post, $thread, $node), 403);

        return view('posts.edit', [
            'post' => $post->load('attachments'),
            'thread' => $thread,
            'node' => $node,
            'breadcrumbs' => [...app(NodeTree::class)->ancestors($node), $node],
            'prefixes' => $post->isFirstPost() ? ThreadPrefix::orderBy('display_order')->get() : collect(),
        ]);
    }

    public function update(Request $request, Post $post)
    {
        [$thread, $node] = $this->visiblePost($post);
        abort_unless($this->canEdit($post, $thread, $node), 403);

        $first = $post->isFirstPost();
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:50000'],
            'title' => [Rule::requiredIf($first), 'nullable', 'string', 'min:3', 'max:150'],
            'prefix_id' => ['nullable', Rule::exists('thread_prefixes', 'id')],
            'tags' => ['nullable', 'string', 'max:300'],
        ]);

        // An edit is screened like a new message: it can be refused, or sent to the moderators afterwards.
        $verdict = $this->posting->screen($node, $request->user(), $data['body'], $first ? (string) $data['title'] : '');

        $this->posting->editPost($post, $request->user(), $data['body']);
        app(Attachments::class)->attach($request->input('attachment_key'), $request->user(), $post);
        if ($first) {
            $thread->update(['title' => app(Censor::class)->clean(\App\Forum\Titles::tidy($data['title'])), 'prefix_id' => $data['prefix_id'] ?? null]);
            app(Tags::class)->sync($thread, $data['tags'] ?? null);
        }

        if ($verdict === 'moderated' && $post->state === 'visible' && $post->user_id === $request->user()->id) {
            $first ? $this->posting->setThreadState($thread, 'moderated', $request->user()) : $this->posting->setPostState($post, 'moderated', $request->user());

            return redirect()->route('nodes.show', $node)->with('info', __('Your changes have been saved and will be visible after a moderator has looked at them.'));
        }

        return redirect()->route('posts.show', $post->id)->with('success', __('The post has been updated.'));
    }

    /** Soft delete. Removing the first post removes the thread and needs moderator rights. */
    public function destroy(Request $request, Post $post)
    {
        [$thread, $node] = $this->visiblePost($post);
        $own = $post->user_id === $request->user()->id;

        if ($post->isFirstPost()) {
            forum_authorize('mod.deleteAny', $node);
            $this->posting->setThreadState($thread, 'deleted', $request->user());

            return redirect()->route('nodes.show', $node)->with('success', __('The thread has been deleted.'));
        }

        abort_unless(forum_can('mod.deleteAny', $node) || ($own && forum_can('post.deleteOwn', $node) && ! $thread->is_locked), 403);
        $this->posting->setPostState($post, 'deleted', $request->user());

        return redirect()->route('threads.show', $thread)->with('success', __('The post has been deleted.'));
    }

    public function moderate(Request $request, Post $post)
    {
        [$thread, $node] = $this->visiblePost($post);
        $data = $request->validate(['action' => ['required', Rule::in(['restore', 'approve', 'hard_delete'])]]);

        forum_authorize(match ($data['action']) {
            'restore' => 'mod.deleteAny',
            'approve' => 'mod.approve',
            'hard_delete' => 'mod.hardDelete',
        }, $node);

        abort_if($post->isFirstPost(), 422, __('Use the thread tools for the first post.'));

        if ($data['action'] === 'hard_delete') {
            $this->posting->setPostState($post, 'deleted', $request->user(), hard: true);

            return redirect()->route('threads.show', $thread)->with('success', __('The post has been permanently deleted.'));
        }

        $this->posting->setPostState($post, 'visible', $request->user());

        return redirect()->route('posts.show', $post->id)->with('success', __('The post has been updated.'));
    }

    public function react(Request $request, Post $post)
    {
        [, $node] = $this->visiblePost($post);
        forum_authorize('post.react', $node);
        abort_if($post->user_id === $request->user()->id, 403, __('You cannot react to your own post.'));

        $data = $request->validate(['reaction_id' => ['required', Rule::exists('reactions', 'id')->where('is_active', true)]]);
        $this->posting->react($post, $request->user(), Reaction::findOrFail($data['reaction_id']));

        return redirect()->to(url()->previous().'#post-'.$post->id);
    }

    public function report(Request $request, Post $post)
    {
        [, $node] = $this->visiblePost($post);
        forum_authorize('post.report', $node);
        // One of the reasons the administrator offers, with details if the member adds any; or free text alone.
        $offered = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) setting('reports.reasons')))));
        $data = $request->validate([
            'reason_type' => ['nullable', 'string', Rule::in($offered)],
            'reason' => [$request->filled('reason_type') ? 'nullable' : 'required', 'string', 'min:5', 'max:1000'],
        ]);
        $reason = trim(($data['reason_type'] ?? '').(filled($data['reason'] ?? null) && filled($data['reason_type'] ?? null) ? ' — ' : '').($data['reason'] ?? ''));

        $report = Report::firstOrCreate(
            ['post_id' => $post->id, 'user_id' => $request->user()->id, 'status' => 'open'],
            ['reason' => $reason],
        );
        // Reporting the same post again changes nothing and is not announced a second time.
        if ($report->wasRecentlyCreated) {
            event('forum.post.reported', [$post, $report]);
        }

        return redirect()->to(url()->previous().'#post-'.$post->id)->with('success', __('Thank you, the post has been reported to the moderators.'));
    }

    /** Raw BBCode of a post wrapped in a quote tag, for the reply editor. */
    public function quote(Post $post)
    {
        $this->visiblePost($post);
        $body = trim(preg_replace('/\[quote.*?\].*\[\/quote\]/is', '', $post->body));
        // Add-ons may keep parts of a message out of quotes (hidden content, for example).
        $body = trim((string) app(Hooks::class)->filter('post.source', $body, $post));
        $author = $post->user?->username ?? __('Deleted member');

        return response()->json(['bbcode' => "[quote=\"{$author}, post: {$post->id}\"]\n{$body}\n[/quote]\n"]);
    }

    /** The thread starter or a moderator marks a reply as the answer (or takes the mark back). */
    public function solution(Request $request, Post $post)
    {
        [$thread, $node] = $this->visiblePost($post);
        abort_unless($node->allow_solutions && $post->isVisible() && ! $post->isFirstPost(), 404);
        abort_unless($thread->user_id === $request->user()->id || forum_can('mod.editAny', $node), 403);

        $clear = $thread->solution_post_id === $post->id;
        $this->posting->setSolution($thread, $clear ? null : $post, $request->user());

        return redirect()->route('posts.show', $post->id)->with('success', $clear ? __('The solution mark has been removed.') : __('The reply has been marked as the solution.'));
    }

    /** Earlier versions of a post, for moderators and the author. */
    public function history(Request $request, Post $post)
    {
        [$thread, $node] = $this->visiblePost($post);
        abort_unless(forum_can('mod.editAny', $node) || $post->user_id === $request->user()->id, 403);

        return view('posts.history', [
            'post' => $post,
            'thread' => $thread,
            'breadcrumbs' => [...app(NodeTree::class)->ancestors($node), $node],
            'edits' => $post->edits()->with('editor')->limit(50)->get(),
        ]);
    }

    /** Rendered BBCode for the editor's preview tab. */
    public function preview(Request $request, Renderer $renderer)
    {
        $data = $request->validate(['body' => ['nullable', 'string', 'max:50000']]);

        return response()->json(['html' => $renderer->render((string) ($data['body'] ?? ''))]);
    }

    protected function canEdit(Post $post, Thread $thread, Node $node): bool
    {
        if (forum_can('mod.editAny', $node)) {
            return true;
        }

        $minutes = (int) setting('posting.edit_minutes');

        return $post->user_id === auth()->id()
            && forum_can('post.editOwn', $node)
            && ! $thread->is_locked
            && ($minutes === 0 || $post->created_at->gt(now()->subMinutes($minutes)));
    }
}
