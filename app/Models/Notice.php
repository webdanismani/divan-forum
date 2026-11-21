<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_dismissible' => 'boolean', 'is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
