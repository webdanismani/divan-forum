<?php

namespace App\Http\Controllers;

use App\Forum\Ai\Assistant;
use App\Forum\Attachments;
use App\Forum\Community;
use App\Forum\Market;
use App\Forum\NodeTree;
use App\Forum\Polls;
use App\Forum\Posting;
use App\Forum\Security\Bans;
use App\Forum\Similar;
use App\Forum\Tags;
use App\Http\Controllers\Concerns\ChecksVisibility;
use App\Models\Bookmark;
use App\Models\Listing;
use App\Models\Node;
use App\Models\ProfileField;
use App\Models\Reaction;
use App\Models\Thread;
use App\Models\ThreadPrefix;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ThreadController extends Controller
{
    use ChecksVisibility;

    public function __construct(protected NodeTree $tree, protected Posting $posting) {}

    public function show(Request $request, Thread $thread)
    {
        $node = $this->visibleThreadNode($thread);
        $user = $request->user();

        $posts = $thread->posts()
            ->visibleIn($node)
            ->with(['user.group', 'user.groups', 'editor', 'reactions.reaction', 'attachments'])
            ->orderBy('id')
            ->paginate((int) setting('board.posts_per_page'));

        DB::table('threads')->where('id', $thread->id)->increment('view_count');

        $watching = false;
        if ($user) {
            $this->markRead($thread, $user->id, $posts->hasMorePages() ? $posts->last()?->created_at : now());
            $user->unreadNotifications()->where('data', 'like', '%"thread_id":'.$thread->id.'}%')->update(['read_at' => now()]);
            $watching = DB::table('thread_watches')->where('user_id', $user->id)->where('thread_id', $thread->id)->exists();
        }

        $thread->load('prefix', 'user', 'tags', 'poll.options', 'listing');

        return view('threads.show', [
            'thread' => $thread,
            'node' => $node,
            'breadcrumbs' => [...$this->tree->ancestors($node), $node],
            'posts' => $posts,
            'reactions' => Reaction::where('is_active', true)->orderBy('display_order')->get(),
            'watching' => $watching,
            'pollChoices' => $thread->poll ? app(Polls::class)->choices($thread->poll, $user) : [],
            'ignored' => app(Community::class)->ignoredIds($user),
            'authorFields' => $this->authorFields($posts->pluck('user_id')->filter()->unique()->all()),
            'bookmarked' => $user ? Bookmark::where('user_id', $user->id)->whereIn('post_id', $posts->pluck('id'))->pluck('post_id')->all() : [],
            'rateTargets' => $this->rateTargets($thread, $user),
            'summary' => $summary = app(Assistant::class)->cachedSummary($thread),
            'canSummarize' => $user && $thread->isVisible() && setting('ai.summaries') && app(Assistant::class)->enabled()
                && $thread->reply_count + 1 >= (int) setting('ai.summary_min_posts') && app(Assistant::class)->summaryIsStale($thread, $summary),
            'similar' => $posts->currentPage() === 1 ? app(Similar::class)->threads($thread->title, $user, $thread->id) : collect(),
            'canReply' => $thread->isVisible() && forum_can('post.create', $node) && (! $thread->is_locked || forum_can('mod.lock', $node)),
            'moveTargets' => forum_can('mod.move', $node)
                ? array_filter($this->tree->flat($this->tree->viewable($user)), fn ($item) => $item['node']->isForum())
                : [],
        ]);
    }

    /**
     * The start of the thread, as a small panel that opens when the pointer rests on its title in a list.
     * Reading it does not count as a view and does not mark anything read.
     */
    public function preview(Request $request, Thread $thread, \App\Forum\BbCode\Renderer $renderer)
    {
        abort_unless(setting('board.thread_preview'), 404);
        $node = $this->visibleThreadNode($thread);

        $first = $thread->posts()->visibleIn($node)->with('user.group')->orderBy('id')->first();
        $ignored = $first && in_array($first->user_id, app(Community::class)->ignoredIds($request->user()), false);
        $thread->load('prefix', 'lastPostUser');

        return response()->view('threads._preview', [
            'thread' => $thread,
            'node' => $node,
            'first' => $first,
            'excerpt' => $first && ! $ignored ? $renderer->plain((string) $first->body, 320) : '',
            'ignored' => $ignored,
        ])->header('Cache-Control', 'private, max-age=60');
    }

    /** How many posts arrived after the given one, and the page they start on. Asked for by an open thread page. */
    public function since(Request $request, Thread $thread, int $after)
    {
        $node = $this->visibleThreadNode($thread);
        abort_unless($thread->isVisible(), 404);

        $new = $thread->posts()->where('state', 'visible')->where('id', '>', $after)
            ->when($request->user(), fn ($q) => $q->where(fn ($w) => $w->whereNull('user_id')->orWhere('user_id', '!=', $request->user()->id)))
            ->orderBy('id')->pluck('id');

        $url = null;
        if ($new->isNotEmpty()) {
            $position = $thread->posts()->visibleIn($node)->where('id', '<=', $new->first())->count();
            $page = max(1, (int) ceil($position / (int) setting('board.posts_per_page')));
            $url = route('threads.show', ['thread' => $thread] + ($page > 1 ? ['page' => $page] : []));
        }

        return response()->json(['count' => $new->count(), 'url' => $url])->header('Cache-Control', 'no-store');
    }

    public function unread(Request $request, Thread $thread)
    {
        $node = $this->visibleThreadNode($thread);
        $readAt = $request->user()
            ? DB::table('thread_reads')->where('user_id', $request->user()->id)->where('thread_id', $thread->id)->value('read_at')
            : null;

        $post = $readAt
            ? $thread->posts()->visibleIn($node)->where('created_at', '>', $readAt)->orderBy('id')->first()
            : null;

        return redirect()->route('posts.show', $post?->id ?? $thread->last_post_id ?? $thread->first_post_id);
    }

    public function create(Node $node)
    {
        $node = $this->postableNode($node);

        return view('threads.create', [
            'node' => $node,
            'breadcrumbs' => [...$this->tree->ancestors($node), $node],
            'prefixes' => ThreadPrefix::orderBy('display_order')->get(),
            'market' => app(Market::class)->isMarket($node),
            'marketRefusal' => app(Market::class)->isMarket($node) ? app(Market::class)->refusal(auth()->user()) : null,
        ]);
    }

    public function store(Request $request, Node $node)
    {
        $node = $this->postableNode($node);

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'min:2', 'max:50000'],
            'prefix_id' => ['nullable', Rule::exists('thread_prefixes', 'id')],
            'tags' => ['nullable', 'string', 'max:300'],
            'poll_question' => ['nullable', 'string', 'max:200'],
            'poll_options' => ['nullable', 'string', 'max:3000'],
            'poll_max_choices' => ['nullable', 'integer', 'min:1', 'max:'.Polls::MAX_OPTIONS],
            'poll_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'listing_type' => ['nullable', Rule::in(array_keys(Listing::TYPES))],
            'listing_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        // What the forum asks of every new thread: a prefix, and a starting text that was actually filled in.
        if ($node->require_prefix && blank($data['prefix_id'] ?? null) && ThreadPrefix::exists()) {
            throw ValidationException::withMessages(['prefix_id' => __('Choose a prefix; this forum does not accept threads without one.')]);
        }
        $squeeze = fn (?string $text) => preg_replace('/\s+/u', '', (string) $text);
        if (filled($node->thread_template) && $squeeze($data['body']) === $squeeze($node->thread_template)) {
            throw ValidationException::withMessages(['body' => __('Fill in the form in the message before posting; it is still as it was given.')]);
        }

        $market = app(Market::class);
        if ($market->isMarket($node) && ($refusal = $market->refusal($request->user()))) {
            throw ValidationException::withMessages(['title' => $refusal]);
        }

        $pollOptions = Polls::parseOptions($data['poll_options'] ?? null);
        $withPoll = filled($data['poll_question'] ?? null) && forum_can('poll.create', $node);
        if ($withPoll && count($pollOptions) < 2) {
            throw ValidationException::withMessages(['poll_options' => __('A poll needs at least two options.')]);
        }
        $this->checkFlood();

        $thread = $this->posting->createThread($node, $request->user(), \App\Forum\Titles::tidy($data['title']), $data['body'], $data['prefix_id'] ?? null, $request->ip());
        app(Attachments::class)->attach($request->input('attachment_key'), $request->user(), $thread->firstPost);
        app(Tags::class)->sync($thread, $data['tags'] ?? null);
        app(Bans::class)->log($request->user(), $request->ip(), 'post');
        // Sticking and locking need their own permissions; the boxes are ignored without them.
        if ($request->boolean('sticky') && forum_can('mod.stick', $node)) {
            $this->posting->toggle($thread, 'is_sticky', true, $request->user());
        }
        if ($request->boolean('locked') && forum_can('mod.lock', $node)) {
            $this->posting->toggle($thread, 'is_locked', true, $request->user());
        }
        if ($market->isMarket($node)) {
            $market->createListing($thread, $data['listing_type'] ?? 'sell', isset($data['listing_price']) ? (float) $data['listing_price'] : null);
        }
        if ($withPoll) {
            app(Polls::class)->create($thread, $data['poll_question'], $pollOptions, (int) ($data['poll_max_choices'] ?? 1), $data['poll_days'] ?? null, ! $request->boolean('poll_locked'));
        }

        return redirect()->route('threads.show', $thread)->with(
            $thread->isVisible() ? 'success' : 'info',
            $thread->isVisible() ? __('Your thread has been posted.') : __('Your thread has been submitted and will be visible after approval.'),
        );
    }

    public function watch(Request $request, Thread $thread)
    {
        $this->visibleThreadNode($thread);
        $key = ['user_id' => $request->user()->id, 'thread_id' => $thread->id];

        if (DB::table('thread_watches')->where($key)->delete()) {
            return back()->with('success', __('You are no longer watching this thread.'));
        }

        DB::table('thread_watches')->insert($key + ['created_at' => now()]);

        return back()->with('success', __('You are now watching this thread.'));
    }

    public function moderate(Request $request, Thread $thread)
    {
        $node = $this->visibleThreadNode($thread);
        $actor = $request->user();

        $data = $request->validate([
            'action' => ['required', Rule::in(['lock', 'unlock', 'stick', 'unstick', 'delete', 'restore', 'approve', 'hard_delete', 'move'])],
            'node_id' => ['required_if:action,move', 'nullable', 'integer'],
        ]);

        $permission = match ($data['action']) {
            'lock', 'unlock' => 'mod.lock',
            'stick', 'unstick' => 'mod.stick',
            'delete', 'restore' => 'mod.deleteAny',
            'approve' => 'mod.approve',
            'hard_delete' => 'mod.hardDelete',
            'move' => 'mod.move',
        };
        forum_authorize($permission, $node);

        switch ($data['action']) {
            case 'lock':
            case 'unlock':
                $this->posting->toggle($thread, 'is_locked', $data['action'] === 'lock', $actor);
                break;
            case 'stick':
            case 'unstick':
                $this->posting->toggle($thread, 'is_sticky', $data['action'] === 'stick', $actor);
                break;
            case 'delete':
                $this->posting->setThreadState($thread, 'deleted', $actor);

                return redirect()->route('nodes.show', $node)->with('success', __('The thread has been deleted.'));
            case 'restore':
            case 'approve':
                $this->posting->setThreadState($thread, 'visible', $actor);
                break;
            case 'hard_delete':
                $this->posting->setThreadState($thread, 'deleted', $actor, hard: true);

                return redirect()->route('nodes.show', $node)->with('success', __('The thread has been permanently deleted.'));
            case 'move':
                $target = $this->viewableNode((int) $data['node_id']);
                abort_unless($target->isForum(), 422);
                // Moving needs the right in the forum the thread goes to as well, as the bulk tools require.
                forum_authorize('mod.move', $target);
                $this->posting->moveThread($thread, $target, $actor);
                break;
        }

        return redirect()->route('threads.show', $thread)->with('success', __('The thread has been updated.'));
    }

    // ---------------------------------------------------------------------

    /** Profile fields marked "show next to posts", per author: [user id => [[label, value], ...]]. */
    protected function authorFields(array $userIds): array
    {
        $labels = ProfileField::where('is_active', true)->where('show_on_posts', true)->orderBy('display_order')->pluck('label', 'id');
        if ($labels->isEmpty() || ! $userIds) {
            return [];
        }

        $out = [];
        foreach (DB::table('profile_field_values')->whereIn('user_id', $userIds)->whereIn('profile_field_id', $labels->keys())->get() as $row) {
            $out[$row->user_id][] = [$labels[$row->profile_field_id], $row->value];
        }

        return $out;
    }

    /** Members the viewer can leave trader feedback for on this listing. */
    protected function rateTargets(Thread $thread, ?User $user)
    {
        if (! $user || ! $thread->listing || ! $thread->isVisible()) {
            return collect();
        }

        $market = app(Market::class);
        $candidates = $user->id === $thread->user_id
            ? User::whereIn('id', $thread->posts()->where('state', 'visible')->whereNotNull('user_id')->where('user_id', '!=', $user->id)->distinct()->limit(40)->pluck('user_id'))->get()
            : collect([$thread->user])->filter();

        return $candidates->filter(fn ($member) => $market->canRate($thread, $user, $member))->values();
    }

    protected function postableNode(Node $node): Node
    {
        $node = $this->viewableNode($node->id);
        abort_unless($node->isForum(), 404);
        forum_authorize('thread.create', $node);

        return $node;
    }

    protected function markRead(Thread $thread, int $userId, $readAt): void
    {
        if (! $readAt) {
            return;
        }

        $key = ['user_id' => $userId, 'thread_id' => $thread->id];
        $existing = DB::table('thread_reads')->where($key)->value('read_at');

        if (! $existing) {
            DB::table('thread_reads')->insertOrIgnore($key + ['read_at' => $readAt]);
        } elseif ($readAt->gt($existing)) {
            DB::table('thread_reads')->where($key)->update(['read_at' => $readAt]);
        }
    }
}
