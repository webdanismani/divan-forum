<?php

namespace App\Models;

use App\Models\Concerns\HasContentState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    use HasContentState;

    protected $guarded = ['id'];

    protected $hidden = ['ip_address'];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(PostReaction::class);
    }

    public function edits(): HasMany
    {
        return $this->hasMany(PostEdit::class)->latest('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function isFirstPost(): bool
    {
        return $this->thread->first_post_id === $this->id;
    }
}
