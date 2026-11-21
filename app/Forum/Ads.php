<?php

namespace App\Forum;

use App\Models\Ad;
use App\Models\AdZone;
use App\Models\Coupon;
use App\Notifications\ForumAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Advertising: which banners are shown where, how many slots are free, and
 * the life cycle of an order (unpaid -> review -> active).
 */
class Ads
{
    /** @var Collection<string, AdZone>|null */
    protected ?Collection $zones = null;

    protected ?Collection $holding = null;

    protected array $shown = [];

    protected bool $flushRegistered = false;

    public function __construct(protected Settings $settings) {}

    /** Forget what was read from the database (called when an ad or a zone changes). */
    public function flush(): void
    {
        $this->zones = null;
        $this->holding = null;
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('ads.enabled');
    }

    /** @return Collection<string, AdZone> keyed by position */
    public function zones(): Collection
    {
        return $this->zones ??= AdZone::orderBy('display_order')->get()->keyBy('position');
    }

    /** Ads that currently occupy one of the zone's slots. */
    public function holding(AdZone $zone): Collection
    {
        if ($this->holding === null) {
            $all = Ad::holdingSlot()->get();
            // An order that continues a running ad waits behind it: together they take one slot.
            $this->holding = $all->reject(fn (Ad $ad) => $ad->renewal_of && $all->contains('id', $ad->renewal_of))->groupBy('zone_id');
        }

        return collect($this->holding->get($zone->id, []));
    }

    public function freeSlots(AdZone $zone): int
    {
        return max(0, $zone->slots - $this->holding($zone)->count());
    }

    /** When the next slot of a full zone becomes available. */
    public function nextFreeAt(AdZone $zone): ?Carbon
    {
        return $this->holding($zone)->where('status', 'active')->min('ends_at');
    }

    /**
     * What to print at a template position.
     *
     * @return array{zone: AdZone, ads: Collection<int, Ad>, placeholders: int}|null
     */
    public function display(string $position): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $zone = $this->zones()->get($position);
            if (! $zone || ! $zone->is_active) {
                return null;
            }
            $ads = $this->holding($zone)->filter(fn (Ad $ad) => $ad->isLive())->shuffle()->take($zone->slots)->values();
            // One placeholder, in the real banner size, for every slot that is still on sale.
            $placeholders = $this->settings->get('ads.placeholders') ? min($this->freeSlots($zone), $zone->slots - $ads->count()) : 0;
        } catch (Throwable) {
            return null; // tables not migrated yet
        }

        if ($ads->isEmpty() && $placeholders < 1) {
            return null;
        }

        $this->countImpressions($ads);

        return ['zone' => $zone, 'ads' => $ads, 'placeholders' => $placeholders];
    }

    /** The card payment was confirmed: publish straight away when allowed, else queue for review. */
    public function markPaid(Ad $ad, string $method, ?float $amount = null, ?string $reference = null, bool $autoPublish = true): void
    {
        // A renewal takes over the slot of the ad it continues, so it does not need a free one.
        $free = $this->freeSlots($ad->zone) + ($ad->renewal_of && Ad::whereKey($ad->renewal_of)->holdingSlot()->exists() ? 1 : 0);

        // Payment providers repeat their notifications, sometimes while the first one is still being handled:
        // the row is locked, and only the call that finds it unpaid goes on.
        $first = DB::transaction(function () use ($ad, $method, $amount, $reference) {
            $row = Ad::whereKey($ad->id)->lockForUpdate()->first();
            if (! $row || $row->paid_at !== null) {
                return false;
            }
            $row->update([
                'paid_at' => now(),
                'paid_amount' => $amount ?? $row->price,
                'payment_method' => $method,
                'payment_ref' => $reference ?? $row->payment_ref,
                'status' => $row->status === 'unpaid' ? 'review' : $row->status,
            ]);

            return true;
        });
        if (! $first) {
            return;
        }
        $ad->refresh();
        $this->holding = null;

        if ($autoPublish && $ad->status === 'review' && $free > 0 && $ad->zone->is_active && $this->settings->get('ads.auto_publish')) {
            $this->publish($ad);
        }
    }

    /** Start (or restart) showing an ad for the number of days that was ordered. */
    public function publish(Ad $ad): void
    {
        $start = $ad->starts_at?->isFuture() ? $ad->starts_at : now();

        $ad->update([
            'status' => 'active',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays($ad->days),
            'paid_at' => $ad->paid_at ?? ($ad->payment_method === 'manual' ? null : now()),
            'paid_amount' => $ad->paid_amount ?? ($ad->payment_method === 'manual' ? null : $ad->price),
        ]);
        $this->holding = null;

        $ad->user?->notify(new ForumAlert('ad_published', null, route('ads.show', $ad), ['title' => $ad->title]));
    }

    /**
     * A new order for the same banner that starts when the current one ends.
     * Returns null when the zone no longer sells that period.
     */
    public function renew(Ad $ad, int $days): ?Ad
    {
        $price = $ad->zone->priceFor($days);
        if ($price === null) {
            return null;
        }

        $image = null;
        if ($ad->image_path && Storage::disk('public')->exists($ad->image_path)) {
            $image = 'ads/'.bin2hex(random_bytes(20)).'.'.pathinfo($ad->image_path, PATHINFO_EXTENSION);
            Storage::disk('public')->copy($ad->image_path, $image);
        }

        return Ad::create([
            'zone_id' => $ad->zone_id,
            'user_id' => $ad->user_id,
            'title' => $ad->title,
            'target_url' => $ad->target_url,
            'image_path' => $image,
            'days' => $days,
            'price' => $price,
            'status' => $price > 0 ? 'unpaid' : 'review',
            'billing_name' => $ad->billing_name,
            'billing_phone' => $ad->billing_phone,
            'billing_address' => $ad->billing_address,
            'starts_at' => $ad->ends_at?->isFuture() ? $ad->ends_at : null,
            'renewal_of' => $ad->ends_at?->isFuture() ? $ad->id : null,
        ]);
    }

    /** Scheduled: tell advertisers a few days before their ad ends. */
    public function remindExpiring(): int
    {
        $days = (int) $this->settings->get('ads.remind_days');
        if ($days < 1 || ! $this->enabled()) {
            return 0;
        }

        $ads = Ad::with('user')->where('status', 'active')->whereNull('reminded_at')
            ->where('ends_at', '>', now())->where('ends_at', '<=', now()->addDays($days))
            // Not when the continuation has been ordered already.
            ->whereNotIn('id', Ad::whereNotNull('renewal_of')->whereIn('status', ['review', 'active'])->select('renewal_of'))
            ->limit(100)->get();

        foreach ($ads as $ad) {
            $ad->update(['reminded_at' => now()]);
            $ad->user?->notify(new ForumAlert('ad_expiring', null, route('ads.show', $ad), ['title' => $ad->title, 'date' => $ad->ends_at->translatedFormat('j F')]));
        }

        return $ads->count();
    }

    public function reject(Ad $ad, ?string $note = null): void
    {
        $ad->update(['status' => 'rejected', 'admin_note' => $note ?: $ad->admin_note]);
        $this->holding = null;

        $ad->user?->notify(new ForumAlert('ad_rejected', null, route('ads.show', $ad), ['title' => $ad->title]));
    }

    public function delete(Ad $ad): void
    {
        // An order that was never paid or shown gives its coupon use back.
        if ($ad->paid_at === null && in_array($ad->status, ['unpaid', 'review', 'rejected'], true)) {
            Coupon::release($ad->coupon_code);
        }
        $ad->deleteImage();
        $ad->delete();
        $this->holding = null;
    }

    /** Impressions are written once, after the response has been sent. */
    protected function countImpressions(Collection $ads): void
    {
        if ($ads->isEmpty()) {
            return;
        }

        array_push($this->shown, ...$ads->pluck('id')->all());

        if (! $this->flushRegistered) {
            $this->flushRegistered = true;
            app()->terminating(function () {
                if ($this->shown) {
                    Ad::whereIn('id', array_unique($this->shown))->increment('impressions');
                    $this->shown = [];
                }
            });
        }
    }
}
