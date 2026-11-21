<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Poll extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['can_change' => 'boolean', 'closes_at' => 'datetime'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('display_order')->orderBy('id');
    }

    public function isClosed(): bool
    {
        return $this->closes_at !== null && $this->closes_at->isPast();
    }
}
