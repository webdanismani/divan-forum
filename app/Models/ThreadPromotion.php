<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreadPromotion extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = ['sticky' => 'Pin to the top', 'highlight' => 'Highlighted title', 'bump' => 'Move to the top once', 'sponsored' => 'Sponsored thread', 'showcase' => 'Marketplace showcase'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['price' => 'float', 'ends_at' => 'datetime'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
