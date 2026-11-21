<?php

namespace App\Http\Controllers;

use App\Forum\NodeTree;
use App\Models\Post;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class SearchController extends Controller
{
    public function __invoke(Request $request, NodeTree $tree)
    {
        forum_authorize('search.use');

        $query = trim((string) $request->query('q'));
        $viewable = $tree->viewable($request->user());
        $filters = [
            'node' => $viewable->has((int) $request->query('node')) ? (int) $request->query('node') : null,
            'author' => trim((string) $request->query('author')),
            'titles' => $request->boolean('titles'),
            'days' => in_array((int) $request->query('days'), [1, 7, 30, 365], true) ? (int) $request->query('days') : null,
            'sort' => $request->query('sort') === 'relevance' ? 'relevance' : 'newest',
        ];
        $results = null;

        if (mb_strlen($query) >= 3) {
            $author = $filters['author'] !== '' ? User::where('username', $filters['author'])->value('id') : null;
            $fulltext = $this->fulltext();

            $builder = Post::query()
                ->select('posts.*')
                ->join('threads', 'threads.id', '=', 'posts.thread_id')
                ->where('posts.state', 'visible')
                ->where('threads.state', 'visible')
                // A forum filter includes its sub-forums the visitor may see.
                ->whereIn('threads.node_id', $filters['node']
                    ? array_values(array_intersect($tree->descendantIds($viewable->get($filters['node'])), $viewable->keys()->all()))
                    : $viewable->keys())
                ->when($filters['author'] !== '', fn (Builder $q) => $q->where('posts.user_id', $author ?? 0))
                ->when($filters['days'], fn (Builder $q, int $days) => $q->where('posts.created_at', '>=', Carbon::now()->subDays($days)))
                // "Titles only" answers with the first post of each matching thread.
                ->when($filters['titles'], fn (Builder $q) => $q->whereColumn('posts.id', 'threads.first_post_id'))
                ->with(['thread.node', 'user.group']);

            if ($fulltext) {
                $match = $filters['titles'] ? 'MATCH(threads.title) AGAINST (?)' : '(MATCH(threads.title) AGAINST (?) * 2 + MATCH(posts.body) AGAINST (?))';
                $bindings = $filters['titles'] ? [$query] : [$query, $query];
                $builder->whereRaw($match.' > 0', $bindings);
                $filters['sort'] === 'relevance' ? $builder->orderByRaw($match.' DESC', $bindings)->orderByDesc('posts.id') : $builder->orderByDesc('posts.id');
            } else {
                $like = '%'.addcslashes($query, '%_\\').'%';
                $builder->where(fn (Builder $q) => $q->where('threads.title', 'like', $like)->when(! $filters['titles'], fn (Builder $q) => $q->orWhere('posts.body', 'like', $like)));
                $builder->orderByDesc('posts.id');
            }

            $results = $builder->paginate(20)->withQueryString();

            // The database looks at the text as it was written. A match that lies only in a part readers do not
            // get to see (an add-on's hidden content) is dropped, so searching cannot be used to confirm it.
            if (! $filters['titles']) {
                $words = array_filter(preg_split('/\s+/u', mb_strtolower($query)), fn ($word) => mb_strlen($word) >= 2);
                $renderer = app(\App\Forum\BbCode\Renderer::class);
                $results->setCollection($results->getCollection()->filter(function (Post $post) use ($words, $renderer) {
                    $seen = mb_strtolower(($post->thread?->title ?? '').' '.$renderer->plain((string) $post->body, 100000));
                    foreach ($words as $word) {
                        if (str_contains($seen, $word)) {
                            return true;
                        }
                    }

                    return ! $words;
                })->values());
            }
        }

        return view('forum.search', [
            'query' => $query,
            'results' => $results,
            'filters' => $filters,
            'forums' => array_filter($tree->flat($viewable), fn ($item) => ! $item['node']->isLink()),
        ]);
    }

    /** A few matches for the search box in the header, while somebody is still typing. */
    public function suggest(Request $request, NodeTree $tree)
    {
        $query = trim((string) $request->query('q'));
        if (mb_strlen($query) < 2 || mb_strlen($query) > 80 || ! forum_can('search.use')) {
            return response()->json(['threads' => [], 'members' => []]);
        }

        $like = addcslashes($query, '%_\\');
        $threads = Thread::with('node')
            ->whereIn('node_id', $tree->viewableIds($request->user()))
            ->where('state', 'visible')
            ->where('title', 'like', '%'.$like.'%')
            ->orderByDesc('last_post_at')->limit(6)->get();

        $members = forum_can('profile.view')
            ? User::where('username', 'like', $like.'%')->where('is_banned', false)->orderBy('username')->limit(4)->get()
            : collect();

        return response()->json([
            'threads' => $threads->map(fn (Thread $thread) => [
                'title' => $thread->title,
                'url' => route('threads.show', $thread),
                'meta' => $thread->node?->title.' · '.trans_choice(':count reply|:count replies', $thread->reply_count, ['count' => forum_number($thread->reply_count)]),
            ])->all(),
            'members' => $members->map(fn (User $member) => ['title' => $member->username, 'url' => route('members.show', $member), 'meta' => $member->displayTitle()])->all(),
            'all' => route('search', ['q' => $query]),
        ])->header('Cache-Control', 'no-store');
    }

    /** Word search needs the indexes that the migration adds on MySQL / MariaDB. */
    protected function fulltext(): bool
    {
        try {
            return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
        } catch (Throwable) {
            return false;
        }
    }
}
