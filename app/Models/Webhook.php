<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Webhook extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['events' => 'array', 'is_active' => 'boolean', 'last_called_at' => 'datetime'];
    }
}
