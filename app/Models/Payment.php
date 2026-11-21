<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a member pays for by card or bank transfer. purpose + subject_id
 * say what is delivered once it is paid (see App\Forum\Payments).
 */
class Payment extends Model
{
    public const STATES = [
        'pending' => ['Awaiting payment', 'amber'],
        'review' => ['Awaiting approval', 'amber'],
        'paid' => ['Paid', 'green'],
        'failed' => ['Failed', 'red'],
        'cancelled' => ['Cancelled', ''],
    ];

    public const PURPOSES = ['ad' => 'Ad', 'wallet' => 'Balance top-up', 'upgrade' => 'Membership package'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'float', 'paid_amount' => 'float', 'paid_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'pending';
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->status][0] ?? $this->status;
    }

    public function stateColor(): string
    {
        return self::STATES[$this->status][1] ?? '';
    }
}
