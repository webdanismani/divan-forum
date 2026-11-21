<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Node extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'require_approval' => 'boolean',
            'allow_solutions' => 'boolean',
            'is_marketplace' => 'boolean',
            'require_prefix' => 'boolean',
            'last_post_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Node $node) {
            if (blank($node->slug)) {
                $node->slug = static::uniqueSlug($node->title, $node->id);
            }
        });
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'forum';
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** The administrator's own icon for this category or forum, if one was uploaded. */
    public function iconUrl(): ?string
    {
        return $this->icon_path ? Storage::disk('public')->url($this->icon_path) : null;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('display_order');
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    public function lastThread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'last_thread_id');
    }

    public function lastPostUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_post_user_id');
    }

    public function permissionEntries(): HasMany
    {
        return $this->hasMany(NodePermission::class);
    }

    public function isCategory(): bool
    {
        return $this->type === 'category';
    }

    public function isForum(): bool
    {
        return $this->type === 'forum';
    }

    public function isLink(): bool
    {
        return $this->type === 'link';
    }
}
