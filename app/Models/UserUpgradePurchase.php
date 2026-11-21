<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserUpgradePurchase extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['price' => 'float', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function upgrade(): BelongsTo
    {
        return $this->belongsTo(UserUpgrade::class, 'user_upgrade_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
