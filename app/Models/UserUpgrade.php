<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A paid membership package: buying it adds the member to a user group for a while. */
class UserUpgrade extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['price' => 'float', 'is_active' => 'boolean'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(UserGroup::class, 'user_group_id');
    }
}
