<?php

namespace App\Forum;

use App\Models\PromotionOption;
use App\Models\Thread;
use App\Models\ThreadPromotion;
use App\Models\User;
use Illuminate\Support\Collection;

/** Paid thread promotion, paid from the member's balance. */
class Promotions
{
    public function __construct(protected Wallet $wallet, protected Posting $posting) {}

    /** @return Collection<string, Collection<int, PromotionOption>> options on sale, grouped by type */
    public function options(?Thread $thread = null): Collection
    {
        return PromotionOption::where('is_active', true)->orderBy('type')->orderBy('days')->get()
            // The showcase is only for marketplace listings.
            ->reject(fn (PromotionOption $option) => $option->type === 'showcase' && $thread && ! $thread->listing)
            ->groupBy('type');
    }

    /** Threads whose owners paid for a place in the "sponsored threads" box. */
    public function sponsored(?User $viewer, int $limit = 5): Collection
    {
        return Thread::with('node', 'user')
            ->whereIn('node_id', app(NodeTree::class)->viewableIds($viewer))
            ->where('state', 'visible')
            ->where('sponsored_until', '>', now())
            ->inRandomOrder()->limit($limit)->get();
    }

    public function buy(Thread $thread, User $user, PromotionOption $option): ThreadPromotion
    {
        abort_if($option->type === 'showcase' && ! $thread->listing, 422);

        $label = __(ThreadPromotion::TYPES[$option->type]).($option->days ? ' · '.__(':days days', ['days' => $option->days]) : '');
        $this->wallet->debit($user, $option->price, 'promotion', $label.': '.$thread->title);

        $endsAt = null;

        if ($option->type === 'bump') {
            // Listed as if it had just been answered.
            $thread->update(['last_post_at' => now()]);
            $this->posting->rebuildNode($thread->node);
        } elseif ($option->type === 'showcase') {
            app(Market::class)->feature($thread->listing, $option->days);
            $endsAt = $thread->listing->featured_until;
        } else {
            $column = ['sticky' => 'promoted_until', 'sponsored' => 'sponsored_until'][$option->type] ?? 'highlight_until';
            $from = $thread->{$column}?->isFuture() ? $thread->{$column} : now();
            $endsAt = $from->copy()->addDays($option->days);
            $thread->update([$column => $endsAt]);
        }

        return ThreadPromotion::create([
            'thread_id' => $thread->id,
            'user_id' => $user->id,
            'type' => $option->type,
            'days' => $option->days,
            'price' => $option->price,
            'ends_at' => $endsAt,
        ]);
    }
}
