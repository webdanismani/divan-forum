<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lastMessageUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_message_user_id');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')->withPivot('last_read_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }
}
