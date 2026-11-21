<?php

namespace App\Http\Controllers;

use App\Forum\NodeTree;
use App\Forum\Privacy;
use App\Http\Controllers\Concerns\ChecksVisibility;
use App\Models\Node;
use App\Models\Post;
use App\Models\Thread;
use App\Models\ThreadPrefix;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ForumController extends Controller
{
    use ChecksVisibility;

    public function __construct(protected NodeTree $tree) {}

    public function index()
    {
        $viewable = $this->tree->viewable(auth()->user());

        return view('forum.index', [
            'sections' => $this->sections(null, $viewable),
            'sidebar' => $this->sidebar($viewable),
            'viewable' => $viewable,
        ]);
    }

    public function show(Request $request, Node $node)
    {
        $node = $this->viewableNode($node->id);
        $user = $request->user();
        $viewable = $this->tree->viewable($user);

        if ($node->isLink()) {
            return redirect()->away($node->link_url ?: route('forum.index'));
        }

        $breadcrumbs = $this->tree->ancestors($node);

        if ($node->isCategory()) {
            return view('forum.index', [
                'category' => $node,
                'breadcrumbs' => $breadcrumbs,
                'sections' => [$this->section($node, $viewable)],
                'sidebar' => null,
            ]);
        }

        // What the visitor asked to see, and in which order. Without a choice: pinned threads first, then by activity.
        $show = in_array($request->query('show'), ['unanswered', 'mine', 'solved', 'unsolved'], true) ? $request->query('show') : null;
        $show = ($show === 'mine' && ! $user) || (in_array($show, ['solved', 'unsolved'], true) && ! $node->allow_solutions) ? null : $show;
        $sort = in_array($request->query('sort'), ['new', 'replies', 'views'], true) ? $request->query('sort') : null;
        $prefixes = ThreadPrefix::whereIn('id', Thread::where('node_id', $node->id)->whereNotNull('prefix_id')->distinct()->select('prefix_id'))->orderBy('title')->get();
        $prefix = $prefixes->firstWhere('id', (int) $request->query('prefix'));
        $plain = ! $show && ! $sort && ! $prefix;

        $threads = Thread::query()
            ->select('threads.*')
            ->where('threads.node_id', $node->id)
            ->visibleIn($node)
            ->when($show === 'unanswered', fn ($q) => $q->where('threads.reply_count', 0))
            ->when($show === 'mine', fn ($q) => $q->where('threads.user_id', $user->id))
            ->when($show === 'solved', fn ($q) => $q->whereNotNull('threads.solution_post_id'))
            ->when($show === 'unsolved', fn ($q) => $q->whereNull('threads.solution_post_id'))
            ->when($prefix, fn ($q) => $q->where('threads.prefix_id', $prefix->id))
            ->when($user, fn ($q) => $q
                ->leftJoin('thread_reads as tr', fn ($join) => $join->on('tr.thread_id', '=', 'threads.id')->where('tr.user_id', $user->id))
                ->addSelect('tr.read_at'))
            ->with(['user.group', 'lastPostUser.group', 'prefix'])
            // Pinned by a moderator or by a paid promotion that is still running — while the list is not filtered or re-sorted.
            ->when($plain, fn ($q) => $q->orderByRaw('case when threads.is_sticky = 1 or threads.promoted_until > ? then 1 else 0 end desc', [now()]))
            ->when($sort === 'new', fn ($q) => $q->orderByDesc('threads.id'))
            ->when($sort === 'replies', fn ($q) => $q->orderByDesc('threads.reply_count'))
            ->when($sort === 'views', fn ($q) => $q->orderByDesc('threads.view_count'))
            ->orderByDesc('threads.last_post_at')
            ->paginate((int) setting('board.threads_per_page'))->withQueryString();

        return view('forum.node', [
            'node' => $node,
            'breadcrumbs' => $breadcrumbs,
            'subforums' => $this->rows($this->tree->children($node->id), $viewable),
            'moderators' => User::with('group')->whereIn('id', DB::table('node_moderators')->where('node_id', $node->id)->select('user_id'))->orderBy('username')->get(),
            'watching' => $user && DB::table('node_watches')->where('user_id', $user->id)->where('node_id', $node->id)->exists(),
            'threads' => $threads,
            'filter' => ['show' => $show, 'sort' => $sort, 'prefix' => $prefix?->id, 'prefixes' => $prefixes, 'plain' => $plain],
        ]);
    }

    public function whatsNew(Request $request)
    {
        $user = $request->user();

        $threads = Thread::query()
            ->select('threads.*')
            ->whereIn('threads.node_id', $this->tree->viewableIds($user))
            ->where('threads.state', 'visible')
            ->when($user, fn ($q) => $q
                ->leftJoin('thread_reads as tr', fn ($join) => $join->on('tr.thread_id', '=', 'threads.id')->where('tr.user_id', $user->id))
                ->addSelect('tr.read_at'))
            ->with(['user.group', 'lastPostUser.group', 'prefix', 'node'])
            ->orderByDesc('threads.last_post_at')
            ->paginate((int) setting('board.threads_per_page'));

        return view('forum.whats-new', ['threads' => $threads]);
    }

    /** Follow a forum: an alert (and e-mail, if wanted) for every new thread in it. */
    public function watch(Request $request, Node $node)
    {
        $node = $this->viewableNode($node->id);
        abort_unless($node->isForum(), 404);
        $key = ['user_id' => $request->user()->id, 'node_id' => $node->id];

        if (DB::table('node_watches')->where($key)->delete()) {
            return back()->with('success', __('You are no longer watching this forum.'));
        }

        DB::table('node_watches')->insert($key + ['created_at' => now()]);

        return back()->with('success', __('You are now watching this forum.'));
    }

    public function markRead(Request $request)
    {
        $request->user()->update(['forums_read_at' => now()]);

        return back()->with('success', __('All forums have been marked as read.'));
    }

    // ---------------------------------------------------------------------

    /** Top level layout: categories become sections, loose forums share one. */
    protected function sections(?int $parentId, Collection $viewable): array
    {
        $sections = [];
        $loose = [];

        foreach ($this->tree->children($parentId) as $node) {
            if (! $viewable->has($node->id)) {
                continue;
            }
            if ($node->isCategory()) {
                $sections[] = $this->section($node, $viewable);
            } else {
                $loose[] = $node;
            }
        }

        if ($loose) {
            array_unshift($sections, ['category' => null, 'rows' => $this->rows(collect($loose), $viewable)]);
        }

        return $sections;
    }

    protected function section(Node $category, Collection $viewable): array
    {
        return ['category' => $category, 'rows' => $this->rows($this->tree->children($category->id), $viewable)];
    }

    /** Forum rows with counters aggregated over their visible sub-forums. */
    protected function rows(Collection $nodes, Collection $viewable): array
    {
        $rows = [];

        foreach ($nodes as $node) {
            if (! $viewable->has($node->id)) {
                continue;
            }

            $family = collect($this->tree->descendantIds($node))
                ->map(fn (int $id) => $viewable->get($id))
                ->filter();
            $latest = $family->filter(fn (Node $n) => $n->last_post_at)->sortByDesc('last_post_at')->first();

            $rows[] = [
                'node' => $node,
                'threads' => $family->sum('thread_count'),
                'posts' => $family->sum('post_count'),
                'latest' => $latest,
                'children' => $this->tree->children($node->id)->filter(fn (Node $n) => $viewable->has($n->id)),
            ];
        }

        // Load "last thread" data for all rows with two queries.
        $latestNodes = collect($rows)->pluck('latest')->filter();
        $threads = Thread::whereIn('id', $latestNodes->pluck('last_thread_id')->filter())->get()->keyBy('id');
        $users = User::with('group')->whereIn('id', $latestNodes->pluck('last_post_user_id')->filter())->get()->keyBy('id');

        foreach ($rows as &$row) {
            $row['lastThread'] = $row['latest'] ? $threads->get($row['latest']->last_thread_id) : null;
            $row['lastUser'] = $row['latest'] ? $users->get($row['latest']->last_post_user_id) : null;
        }

        return $rows;
    }

    protected function sidebar(Collection $viewable): array
    {
        $user = auth()->user();
        $threads = fn () => Thread::query()
            ->select('threads.*')
            ->with(['lastPostUser.group', 'node', 'prefix'])
            ->whereIn('threads.node_id', $viewable->keys())
            ->where('threads.state', 'visible')
            ->when($user, fn ($q) => $q
                ->leftJoin('thread_reads as tr', fn ($join) => $join->on('tr.thread_id', '=', 'threads.id')->where('tr.user_id', $user->id))
                ->addSelect('tr.read_at'));

        return [
            'threadCount' => $this->tree->all()->sum('thread_count'),
            'postCount' => $this->tree->all()->sum('post_count'),
            'memberCount' => User::count(),
            'newestMember' => User::latest('id')->first(),
            'online' => User::with('group')->online()
                ->orderByDesc('last_seen_at')->limit(40)->get()
                // Members who hide their online state are only listed for themselves and the staff.
                ->filter(fn (User $member) => app(Privacy::class)->showsOnline(auth()->user(), $member))->values(),
            'recentReplied' => $threads()->orderByDesc('last_post_at')->limit(10)->get(),
            'recentCreated' => $threads()->orderByDesc('id')->limit(10)->get(),
            'popular' => $threads()->where('last_post_at', '>', now()->subDays(30))
                ->orderByDesc('reply_count')->orderByDesc('view_count')->limit(10)->get(),
            // Right-hand column
            'today' => [
                'threads' => Thread::where('state', 'visible')->where('created_at', '>=', today())->count(),
                'posts' => Post::where('state', 'visible')->where('created_at', '>=', today())->count(),
                'members' => User::where('created_at', '>=', today())->count(),
            ],
            'topPosters' => User::with('group')->where('post_count', '>', 0)->orderByDesc('post_count')->limit(5)->get(),
            'topReacted' => User::with('group')->where('reaction_score', '>', 0)->orderByDesc('reaction_score')->limit(5)->get(),
            'newMembers' => User::with('group')->latest('id')->limit(5)->get(),
        ];
    }
}
