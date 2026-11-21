<?php

namespace App\Forum;

use App\Models\Listing;
use App\Models\Node;
use App\Models\Post;
use App\Models\Thread;
use App\Models\TradeFeedback;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marketplace: in forums marked as a marketplace every thread is a listing
 * (for sale / wanted / service, price, open-sold-closed), and the people who
 * dealt with each other can leave trader feedback.
 */
class Market
{
    public function __construct(protected Settings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('market.enabled');
    }

    public function isMarket(Node $node): bool
    {
        return $this->enabled() && $node->is_marketplace && $node->type === 'forum';
    }

    /** Why this member may not post a listing, or null when they may. */
    public function refusal(User $user): ?string
    {
        if ($this->settings->get('market.require_phone') && ! $user->phone_verified_at && ! $user->group?->is_staff) {
            return __('Only members with a verified phone number can post listings. Verify your number in your account settings.');
        }

        return null;
    }

    public function createListing(Thread $thread, string $type, ?float $price): Listing
    {
        return Listing::create([
            'thread_id' => $thread->id,
            'user_id' => $thread->user_id,
            'type' => isset(Listing::TYPES[$type]) ? $type : 'sell',
            'price' => $price !== null && $price > 0 ? round($price, 2) : null,
        ]);
    }

    /** Extends the paid showcase place of a listing. */
    public function feature(Listing $listing, int $days): void
    {
        $from = $listing->isFeatured() ? $listing->featured_until : now();
        $listing->update(['featured_until' => $from->copy()->addDays($days)]);
    }

    // ---- Trader feedback ----------------------------------------------------------------------

    /**
     * Who may rate whom on a listing: anybody who wrote in the thread may rate
     * its owner, and the owner may rate anybody who wrote in it.
     */
    public function canRate(Thread $thread, User $from, User $to): bool
    {
        if ($from->id === $to->id || $from->isBanned() || $from->post_count < (int) $this->settings->get('market.feedback_min_posts')) {
            return false;
        }

        $owner = $thread->user_id;
        $other = $from->id === $owner ? $to->id : ($to->id === $owner ? $from->id : null);

        return $other !== null
            && Post::where('thread_id', $thread->id)->where('user_id', $other)->where('state', 'visible')->exists()
            && ! TradeFeedback::where('thread_id', $thread->id)->where('from_user_id', $from->id)->where('to_user_id', $to->id)->exists();
    }

    public function rate(Thread $thread, User $from, User $to, int $rating, string $comment): TradeFeedback
    {
        if (! $this->canRate($thread, $from, $to)) {
            throw ValidationException::withMessages(['comment' => __('You cannot leave feedback for this member on this listing.')]);
        }

        $feedback = TradeFeedback::create([
            'thread_id' => $thread->id,
            'from_user_id' => $from->id,
            'to_user_id' => $to->id,
            'rating' => max(-1, min(1, $rating)),
            // The author's side of the deal: the owner of a "for sale" listing is the seller.
            'role' => ($from->id === $thread->user_id) === (($thread->listing?->type ?? 'sell') !== 'buy') ? 'seller' : 'buyer',
            'comment' => $comment,
        ]);
        $this->recount($to);

        return $feedback;
    }

    public function remove(TradeFeedback $feedback): void
    {
        $to = User::find($feedback->to_user_id);
        $feedback->delete();
        if ($to) {
            $this->recount($to);
        }
    }

    public function recount(User $user): void
    {
        // One member counts once towards the score (their latest rating), however many listings the two share:
        // otherwise two accounts could rate each other up without end.
        $latest = DB::table('trade_feedback')->where('to_user_id', $user->id)->selectRaw('max(id) as id')->groupBy('from_user_id');
        $counts = DB::table('trade_feedback')->joinSub($latest, 'latest', 'latest.id', '=', 'trade_feedback.id')
            ->selectRaw('rating, count(*) as total')->groupBy('rating')->pluck('total', 'rating');

        $user->forceFill([
            'trade_positive' => (int) ($counts[1] ?? 0),
            'trade_neutral' => (int) ($counts[0] ?? 0),
            'trade_negative' => (int) ($counts[-1] ?? 0),
        ])->saveQuietly();
    }

    /** "98% (41)" style score: share of positive among positive + negative, or null without any feedback. */
    public static function score(User $user): ?int
    {
        $rated = $user->trade_positive + $user->trade_negative;

        return $rated > 0 ? (int) round($user->trade_positive / $rated * 100) : ($user->trade_neutral > 0 ? 100 : null);
    }
}
