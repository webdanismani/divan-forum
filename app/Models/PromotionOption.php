<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A paid way to push a thread: type sticky|highlight (for "days") or bump (once). */
class PromotionOption extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['price' => 'float', 'is_active' => 'boolean'];
    }
}
