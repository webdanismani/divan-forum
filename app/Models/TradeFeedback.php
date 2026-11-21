<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeFeedback extends Model
{
    protected $table = 'trade_feedback';

    protected $guarded = [];

    public const RATINGS = [1 => ['Positive', 'green'], 0 => ['Neutral', ''], -1 => ['Negative', 'red']];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }
}
