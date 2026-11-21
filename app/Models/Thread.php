<?php

namespace App\Models;

use App\Models\Concerns\HasContentState;
use App\Models\Concerns\HasSlugId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Thread extends Model
{
    use HasContentState, HasSlugId;

    protected string $slugSource = 'title';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_sticky' => 'boolean',
            'is_locked' => 'boolean',
            'last_post_at' => 'datetime',
            'promoted_until' => 'datetime',
            'highlight_until' => 'datetime',
            'sponsored_until' => 'datetime',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prefix(): BelongsTo
    {
        return $this->belongsTo(ThreadPrefix::class, 'prefix_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function poll(): HasOne
    {
        return $this->hasOne(Poll::class);
    }

    /** Set for threads in a marketplace forum. */
    public function listing(): HasOne
    {
        return $this->hasOne(Listing::class);
    }

    public function isSponsored(): bool
    {
        return $this->sponsored_until !== null && $this->sponsored_until->isFuture();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    public function isSolved(): bool
    {
        return $this->solution_post_id !== null;
    }

    public function firstPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'first_post_id');
    }

    public function lastPostUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_post_user_id');
    }

    /** Pinned by a moderator or through a paid promotion that is still running. */
    public function isPinned(): bool
    {
        return $this->is_sticky || ($this->promoted_until !== null && $this->promoted_until->isFuture());
    }

    public function isHighlighted(): bool
    {
        return $this->highlight_until !== null && $this->highlight_until->isFuture();
    }

    /** Whether the thread has posts the given member has not read yet. */
    public function isUnreadFor(?User $user): bool
    {
        if (! $user || ! $this->last_post_at) {
            return false;
        }

        $cutoff = now()->subDays(config('forum.unread_days'));
        if ($user->forums_read_at && $user->forums_read_at->gt($cutoff)) {
            $cutoff = $user->forums_read_at;
        }
        // "read_at" is selected by the listing queries (left join on thread_reads).
        $readAt = $this->getAttribute('read_at');
        if ($readAt && ($readAt = Carbon::parse($readAt))->gt($cutoff)) {
            $cutoff = $readAt;
        }

        return $this->last_post_at->gt($cutoff);
    }
}
