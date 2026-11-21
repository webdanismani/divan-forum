<?php

namespace App\Http\Controllers;

use App\Forum\Addons\Hooks;
use App\Forum\BbCode\Renderer;
use App\Forum\NodeTree;
use App\Forum\Posting;
use App\Forum\Tags;
use App\Http\Controllers\Concerns\ChecksVisibility;
use App\Models\Node;
use App\Models\Post;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** REST API, version 1. Every answer is JSON; permissions are those of the key's owner. */
class ApiController extends Controller
{
    use ChecksVisibility;

    public function __construct(protected NodeTree $tree, protected Posting $posting) {}

    public function me(Request $request)
    {
        abort_unless($request->user(), 401);

        return ['data' => $this->member($request->user()) + ['email' => $request->user()->email]];
    }

    public function forums(Request $request)
    {
        return ['data' => $this->tree->viewable($request->user())->map(fn (Node $node) => [
            'id' => $node->id,
            'parent_id' => $node->parent_id,
            'type' => $node->type,
            'title' => $node->title,
            'description' => $node->description,
            'thread_count' => $node->thread_count,
            'post_count' => $node->post_count,
            'url' => route('nodes.show', $node),
        ])->values()];
    }

    public function threads(int $forum)
    {
        $node = $this->viewableNode($forum);

        $threads = Thread::with('user')->where('node_id', $node->id)->where('state', 'visible')
            ->orderByDesc('last_post_at')->paginate(20);

        return $this->paginated($threads, fn (Thread $thread) => $this->thread($thread));
    }

    public function thread(Thread|int $thread): array
    {
        if (is_int($thread)) {
            $thread = Thread::with(['user', 'firstPost'])->findOrFail($thread);
            $this->visibleThreadNode($thread);
            abort_unless($thread->state === 'visible', 404);

            return ['data' => $this->thread($thread) + ['first_post' => $thread->firstPost ? $this->post($thread->firstPost) : null]];
        }

        return [
            'id' => $thread->id,
            'forum_id' => $thread->node_id,
            'title' => $thread->title,
            'author' => $thread->user ? ['id' => $thread->user->id, 'username' => $thread->user->username] : null,
            'reply_count' => $thread->reply_count,
            'view_count' => $thread->view_count,
            'is_sticky' => $thread->is_sticky,
            'is_locked' => $thread->is_locked,
            'created_at' => $thread->created_at?->toAtomString(),
            'last_post_at' => $thread->last_post_at?->toAtomString(),
            'url' => route('threads.show', $thread),
        ];
    }

    public function posts(int $thread)
    {
        $thread = Thread::findOrFail($thread);
        $this->visibleThreadNode($thread);
        abort_unless($thread->state === 'visible', 404);

        $posts = $thread->posts()->with('user')->where('state', 'visible')->orderBy('id')->paginate(20);

        return $this->paginated($posts, fn (Post $post) => $this->post($post));
    }

    public function storeThread(Request $request, int $forum)
    {
        abort_unless($request->user(), 401);
        $node = $this->viewableNode($forum);
        abort_unless($node->isForum(), 404);
        forum_authorize('thread.create', $node);

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'min:2', 'max:50000'],
            'tags' => ['nullable', 'string', 'max:300'],
            'prefix_id' => ['nullable', Rule::exists('thread_prefixes', 'id')],
            'listing_type' => ['nullable', Rule::in(array_keys(\App\Models\Listing::TYPES))],
            'listing_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        // What the forum asks of every new thread holds here too (see ThreadController@store).
        if ($node->require_prefix && blank($data['prefix_id'] ?? null) && \App\Models\ThreadPrefix::exists()) {
            throw ValidationException::withMessages(['prefix_id' => __('Choose a prefix; this forum does not accept threads without one.')]);
        }
        $squeeze = fn (?string $text) => preg_replace('/\s+/u', '', (string) $text);
        if (filled($node->thread_template) && $squeeze($data['body']) === $squeeze($node->thread_template)) {
            throw ValidationException::withMessages(['body' => __('Fill in the form in the message before posting; it is still as it was given.')]);
        }
        $market = app(\App\Forum\Market::class);
        if ($market->isMarket($node) && ($refusal = $market->refusal($request->user()))) {
            throw ValidationException::withMessages(['title' => $refusal]);
        }
        $this->checkFlood();

        $thread = $this->posting->createThread($node, $request->user(), \App\Forum\Titles::tidy($data['title']), $data['body'], $data['prefix_id'] ?? null, $request->ip());
        app(Tags::class)->sync($thread, $data['tags'] ?? null);
        if ($market->isMarket($node)) {
            $market->createListing($thread, $data['listing_type'] ?? 'sell', isset($data['listing_price']) ? (float) $data['listing_price'] : null);
        }

        return response()->json(['data' => $this->thread($thread->load('user')) + ['state' => $thread->state]], 201);
    }

    public function storePost(Request $request, int $thread)
    {
        abort_unless($request->user(), 401);
        $thread = Thread::findOrFail($thread);
        $node = $this->visibleThreadNode($thread);
        forum_authorize('post.create', $node);
        abort_unless($thread->state === 'visible', 404);
        abort_if($thread->is_locked && ! forum_can('mod.lock', $node), 403, __('This thread is locked.'));

        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:50000']]);
        $this->checkFlood();

        $post = $this->posting->reply($thread, $request->user(), $data['body'], $request->ip());

        return response()->json(['data' => $this->post($post->load('user')) + ['state' => $post->state]], 201);
    }

    public function showMember(int $member)
    {
        forum_authorize('profile.view');

        return ['data' => $this->member(User::with('group')->findOrFail($member))];
    }

    // ---------------------------------------------------------------------

    protected function post(Post $post): array
    {
        return [
            'id' => $post->id,
            'thread_id' => $post->thread_id,
            'author' => $post->user ? ['id' => $post->user->id, 'username' => $post->user->username] : null,
            'body' => app(Hooks::class)->filter('post.source', $post->body, $post),
            'body_html' => app(Hooks::class)->filter('post.html', $post->body_html, $post),
            'text' => app(Renderer::class)->plain($post->body, 500),
            'created_at' => $post->created_at?->toAtomString(),
            'url' => route('posts.show', $post->id),
        ];
    }

    protected function member(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'title' => $user->displayTitle(),
            'post_count' => $user->post_count,
            'thread_count' => $user->thread_count,
            'reaction_score' => $user->reaction_score,
            'joined_at' => $user->created_at?->toAtomString(),
            'url' => route('members.show', $user),
        ];
    }

    protected function paginated($paginator, callable $map): array
    {
        return [
            'data' => collect($paginator->items())->map($map)->values(),
            'meta' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ];
    }
}
