<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NavLink extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['new_tab' => 'boolean', 'is_active' => 'boolean'];
    }
}
