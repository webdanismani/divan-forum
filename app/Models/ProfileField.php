<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileField extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['options' => 'array', 'is_required' => 'boolean', 'show_on_posts' => 'boolean', 'is_active' => 'boolean'];
    }
}
