<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Listing extends Model
{
    protected $guarded = [];

    public const TYPES = ['sell' => 'For sale', 'buy' => 'Wanted', 'service' => 'Service'];

    public const STATES = ['open' => ['Open', 'green'], 'sold' => ['Sold', 'red'], 'closed' => ['Closed', '']];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFeatured(): bool
    {
        return $this->featured_until !== null && $this->featured_until->isFuture();
    }

    protected function casts(): array
    {
        return ['price' => 'float', 'featured_until' => 'datetime'];
    }
}
