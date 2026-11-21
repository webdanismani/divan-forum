<?php

namespace App\Http\Controllers;

use App\Forum\NodeTree;
use App\Forum\Posting;
use App\Http\Controllers\Concerns\ChecksVisibility;
use App\Models\Node;
use App\Models\Post;
use App\Models\Report;
use App\Models\Thread;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ModerationController extends Controller
{
    use ChecksVisibility;

    protected const THREAD_ACTIONS = [
        'approve' => 'mod.approve', 'delete' => 'mod.deleteAny', 'restore' => 'mod.deleteAny', 'hard_delete' => 'mod.hardDelete',
        'lock' => 'mod.lock', 'unlock' => 'mod.lock', 'stick' => 'mod.stick', 'unstick' => 'mod.stick',
        'move' => 'mod.move', 'merge' => 'mod.merge', 'copy' => 'mod.merge',
    ];

    protected const POST_ACTIONS = [
        'approve' => 'mod.approve', 'delete' => 'mod.deleteAny', 'restore' => 'mod.deleteAny',
        'move' => 'mod.merge', 'split' => 'mod.merge',
    ];

    public function reports(Request $request)
    {
        forum_authorize('mod.manageReports');
        $status = $request->query('status') === 'closed' ? 'closed' : 'open';

        // Only reports from forums this moderator can open: the list shows part of the reported text.
        $nodes = app(\App\Forum\NodeTree::class)->viewableIds($request->user());

        $reports = Report::with(['post.thread', 'post.user', 'user', 'handler'])
            ->where(fn ($q) => $q->whereDoesntHave('post')->orWhereHas('post.thread', fn ($t) => $t->whereIn('node_id', $nodes)))
            ->when($status === 'open', fn ($q) => $q->where('status', 'open'), fn ($q) => $q->where('status', '!=', 'open'))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('moderation.reports', compact('reports', 'status'));
    }

    public function updateReport(Request $request, Report $report)
    {
        forum_authorize('mod.manageReports');
        $data = $request->validate(['status' => ['required', Rule::in(['resolved', 'rejected'])]]);

        $report->update($data + ['handled_by' => $request->user()->id, 'handled_at' => now()]);

        return back()->with('success', __('The report has been closed.'));
    }

    /** Threads and replies waiting for approval in forums the moderator manages. */
    public function queue(Request $request, NodeTree $tree)
    {
        $nodeIds = $tree->viewable($request->user())->filter(fn ($node) => forum_can('mod.approve', $node))->keys();
        abort_if($nodeIds->isEmpty(), 403);

        $threads = Thread::with(['user.group', 'node', 'firstPost'])
            ->whereIn('node_id', $nodeIds)->where('state', 'moderated')->latest('id')->limit(50)->get();

        $posts = Post::with(['user.group', 'thread'])
            ->where('posts.state', 'moderated')
            ->whereHas('thread', fn ($q) => $q->whereIn('node_id', $nodeIds)->where('state', 'visible'))
            ->latest('id')->limit(50)->get();

        return view('moderation.queue', compact('threads', 'posts'));
    }

    /** One action on the threads ticked in a forum's thread list. */
    public function threads(Request $request, Node $node, Posting $posting)
    {
        $node = $this->viewableNode($node->id);
        $actor = $request->user();

        $data = $request->validate([
            'threads' => ['required', 'array', 'min:1', 'max:100'],
            'threads.*' => ['integer'],
            'action' => ['required', Rule::in(array_keys(self::THREAD_ACTIONS))],
            'node_id' => ['required_if:action,move,copy', 'nullable', 'integer'],
        ]);
        $action = $data['action'];
        forum_authorize(self::THREAD_ACTIONS[$action], $node);

        $threads = Thread::where('node_id', $node->id)->whereIn('id', $data['threads'])->orderBy('id')->get()
            ->filter(fn (Thread $thread) => $this->stateVisible($thread->state, $thread->user_id, $node))
            ->each(fn (Thread $thread) => $thread->setRelation('node', $node))
            ->values();
        abort_if($threads->isEmpty(), 404);

        $target = null;
        if (in_array($action, ['move', 'copy'], true)) {
            $target = $this->viewableNode((int) $data['node_id']);
            abort_unless($target->isForum(), 422);
            forum_authorize(self::THREAD_ACTIONS[$action], $target);
        }

        if ($action === 'merge') {
            if ($threads->count() < 2) {
                throw ValidationException::withMessages(['threads' => __('Choose at least two threads to merge.')]);
            }
            $merged = $posting->mergeThreads($threads, $actor);

            return redirect()->route('threads.show', $merged)->with('success', __('The threads have been merged.'));
        }

        foreach ($threads as $thread) {
            match ($action) {
                'approve', 'restore' => $posting->setThreadState($thread, 'visible', $actor),
                'delete' => $posting->setThreadState($thread, 'deleted', $actor),
                'hard_delete' => $posting->setThreadState($thread, 'deleted', $actor, hard: true),
                'lock', 'unlock' => $posting->toggle($thread, 'is_locked', $action === 'lock', $actor),
                'stick', 'unstick' => $posting->toggle($thread, 'is_sticky', $action === 'stick', $actor),
                'move' => $target->id !== $node->id ? $posting->moveThread($thread, $target, $actor) : null,
                'copy' => $posting->copyThread($thread, $target, $actor),
            };
        }

        return redirect()->route('nodes.show', $node)->with('success', trans_choice(':count thread was updated.|:count threads were updated.', $threads->count(), ['count' => $threads->count()]));
    }

    /** One action on the posts ticked in a thread. */
    public function posts(Request $request, Thread $thread, Posting $posting)
    {
        $node = $this->visibleThreadNode($thread);
        $actor = $request->user();

        $data = $request->validate([
            'posts' => ['required', 'array', 'min:1', 'max:200'],
            'posts.*' => ['integer'],
            'action' => ['required', Rule::in(array_keys(self::POST_ACTIONS))],
            'target' => ['required_if:action,move', 'nullable', 'string', 'max:500'],
            'title' => ['required_if:action,split', 'nullable', 'string', 'min:3', 'max:150'],
            'node_id' => ['nullable', 'integer'],
        ]);
        $action = $data['action'];
        forum_authorize(self::POST_ACTIONS[$action], $node);

        $posts = $thread->posts()->whereIn('id', $data['posts'])->orderBy('id')->get()
            ->each(fn (Post $post) => $post->setRelation('thread', $thread));
        abort_if($posts->isEmpty(), 404);

        if ($action === 'move') {
            $target = $this->threadFromInput($data['target']);
            if (! $target || $target->id === $thread->id) {
                throw ValidationException::withMessages(['target' => __('Enter the address or number of another thread.')]);
            }
            forum_authorize('mod.merge', $this->visibleThreadNode($target));
            $posting->movePosts($posts, $target, $actor);

            return redirect()->route('threads.show', $target)->with('success', __('The posts have been moved.'));
        }

        if ($action === 'split') {
            $target = filled($data['node_id'] ?? null) ? $this->viewableNode((int) $data['node_id']) : $node;
            abort_unless($target->isForum(), 422);
            forum_authorize('mod.merge', $target);
            $new = $posting->splitPosts($posts, $target, \App\Forum\Titles::tidy($data['title']), $actor);

            return redirect()->route('threads.show', $new)->with('success', __('A new thread has been created from the selected posts.'));
        }

        // The first post stands for the thread itself: use the thread tools for it.
        // Each tool only touches posts in the state it is meant for: "approve" does not bring deleted posts back,
        // "restore" does not let waiting posts through.
        $from = ['approve' => ['moderated'], 'restore' => ['deleted'], 'delete' => ['visible', 'moderated']][$action];
        foreach ($posts->reject(fn (Post $post) => $post->id === $thread->first_post_id || ! in_array($post->state, $from, true)) as $post) {
            $posting->setPostState($post, $action === 'delete' ? 'deleted' : 'visible', $actor);
        }

        return redirect()->route('threads.show', $thread)->with('success', __('The posts have been updated.'));
    }

    /** Accepts a thread address (…/threads/title.123) or a bare number. */
    protected function threadFromInput(string $input): ?Thread
    {
        $input = trim($input);
        $id = ctype_digit($input) ? (int) $input : (preg_match('~/threads/[^/?#]*?\.?(\d+)(?:[/?#]|$)~', $input, $m) ? (int) $m[1] : null);

        return $id ? Thread::find($id) : null;
    }
}
