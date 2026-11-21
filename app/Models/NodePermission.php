<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodePermission extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }
}
