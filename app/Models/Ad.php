<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A banner order. status: unpaid (waiting for payment), review (waiting for
 * the admin), active, paused, rejected. "expired" and "scheduled" are derived
 * from the dates, see state().
 */
class Ad extends Model
{
    public const STATES = [
        'unpaid' => ['Awaiting payment', 'amber'],
        'review' => ['Awaiting approval', 'amber'],
        'scheduled' => ['Scheduled', 'blue'],
        'active' => ['Live', 'green'],
        'paused' => ['Paused', ''],
        'expired' => ['Expired', ''],
        'rejected' => ['Rejected', 'red'],
    ];

    public const METHODS = ['card' => 'Card', 'transfer' => 'Bank transfer', 'balance' => 'Balance', 'manual' => 'Added by an administrator'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'paid_amount' => 'float',
            'paid_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(AdZone::class, 'zone_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ads that are being shown right now. */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }

    /** Ads that hold one of the zone's slots: live, scheduled, or paid and waiting for approval. */
    public function scopeHoldingSlot(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('status', 'active')->where('ends_at', '>', now()))
            ->orWhere(fn (Builder $q) => $q->where('status', 'review')->whereNotNull('paid_at')));
    }

    public function isLive(): bool
    {
        return $this->state() === 'active';
    }

    public function state(): string
    {
        if ($this->status !== 'active') {
            return $this->status;
        }
        if ($this->ends_at === null || $this->ends_at->isPast()) {
            return 'expired';
        }

        return $this->starts_at?->isFuture() ? 'scheduled' : 'active';
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->state()][0] ?? $this->status;
    }

    public function stateColor(): string
    {
        return self::STATES[$this->state()][1] ?? '';
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function deleteImage(): void
    {
        if ($this->image_path) {
            Storage::disk('public')->delete($this->image_path);
        }
    }
}
