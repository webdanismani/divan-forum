<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThreadSummary extends Model
{
    protected $guarded = [];

    protected $primaryKey = 'thread_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return [];
    }
}
