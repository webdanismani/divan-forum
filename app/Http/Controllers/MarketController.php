<?php

namespace App\Http\Controllers;

use App\Forum\Market;
use App\Forum\NodeTree;
use App\Http\Controllers\Concerns\ChecksVisibility;
use App\Models\Listing;
use App\Models\Thread;
use App\Models\TradeFeedback;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The marketplace page, listing states and trader feedback. */
class MarketController extends Controller
{
    use ChecksVisibility;

    public function __construct(protected Market $market, protected NodeTree $tree) {}

    public function index(Request $request)
    {
        abort_unless($this->market->enabled(), 404);

        $filters = $request->validate([
            'type' => ['nullable', Rule::in(array_keys(Listing::TYPES))],
            'forum' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:80'],
            'min' => ['nullable', 'numeric', 'min:0'],
            'max' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['open', 'all'])],
        ]);

        $forums = $this->tree->viewable($request->user())->filter(fn ($node) => $this->market->isMarket($node));
        $nodeIds = isset($filters['forum']) && $forums->has((int) $filters['forum']) ? [(int) $filters['forum']] : $forums->keys()->all();

        $query = Listing::query()
            ->join('threads', 'threads.id', '=', 'listings.thread_id')
            ->whereIn('threads.node_id', $nodeIds)
            ->where('threads.state', 'visible')
            ->when(($filters['status'] ?? 'open') === 'open', fn ($q) => $q->where('listings.status', 'open'))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('listings.type', $type))
            ->when(filled($filters['q'] ?? null), fn ($q) => $q->where('threads.title', 'like', '%'.addcslashes($filters['q'], '%_\\').'%'))
            ->when(isset($filters['min']), fn ($q) => $q->where('listings.price', '>=', $filters['min']))
            ->when(isset($filters['max']), fn ($q) => $q->where('listings.price', '<=', $filters['max']))
            ->select('listings.*');

        return view('market.index', [
            'forums' => $forums,
            'filters' => $filters,
            // The paid showcase: always on top, whatever the filters say about order.
            'featured' => (clone $query)->where('listings.featured_until', '>', now())->with('thread.user', 'thread.node')->inRandomOrder()->limit(8)->get(),
            'listings' => $query->with('thread.user', 'thread.node')->orderByDesc('threads.last_post_at')->paginate(30)->withQueryString(),
        ]);
    }

    /** The owner (or a moderator) marks a listing as sold, closed or open again; type and price can be corrected. */
    public function update(Request $request, Thread $thread)
    {
        $node = $this->visibleThreadNode($thread);
        $listing = Listing::where('thread_id', $thread->id)->firstOrFail();
        abort_unless($thread->user_id === $request->user()->id || forum_can('mod.editAny', $node), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Listing::STATES))],
            'type' => ['nullable', Rule::in(array_keys(Listing::TYPES))],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        $listing->update([
            'status' => $data['status'],
            'type' => $data['type'] ?? $listing->type,
            'price' => $request->has('price') ? (($data['price'] ?? 0) > 0 ? round((float) $data['price'], 2) : null) : $listing->price,
        ]);

        return back()->with('success', __('The listing has been updated.'));
    }

    public function rate(Request $request, Thread $thread)
    {
        $this->visibleThreadNode($thread);
        abort_unless($thread->state === 'visible' && Listing::where('thread_id', $thread->id)->exists(), 404);

        $data = $request->validate([
            'to' => ['required', 'integer', Rule::exists('users', 'id')],
            'rating' => ['required', Rule::in(['1', '0', '-1', 1, 0, -1])],
            'comment' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        $this->market->rate($thread->load('listing'), $request->user(), User::findOrFail($data['to']), (int) $data['rating'], $data['comment']);

        return back()->with('success', __('Thank you, your feedback has been saved.'));
    }

    public function destroyFeedback(Request $request, TradeFeedback $feedback)
    {
        abort_unless($request->user()->group?->is_staff || $request->user()->isAdmin(), 403);
        $this->market->remove($feedback);

        return back()->with('success', __('The feedback has been deleted.'));
    }

    public function feedback(User $user)
    {
        abort_unless($this->market->enabled(), 404);
        forum_authorize('profile.view');

        return view('market.feedback', [
            'member' => $user,
            'score' => Market::score($user),
            'feedback' => TradeFeedback::with('author', 'thread')->where('to_user_id', $user->id)->latest('id')->paginate(25),
            // The listing is named only where the visitor could open it.
            'openNodes' => $this->tree->viewableIds(request()->user()),
        ]);
    }
}
