<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The text of a post as it was before an edit. */
class PostEdit extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
