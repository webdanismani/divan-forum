<?php

namespace App\Models;

use App\Forum\Permissions\PermissionResolver;
use App\Forum\Rewards;
use App\Models\Concerns\HasSlugId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasSlugId, Notifiable;

    protected string $slugSource = 'username';

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'last_ip', 'email', 'two_factor_secret', 'two_factor_recovery_codes', 'registration_ip', 'legacy_password'];

    protected static function booted(): void
    {
        // A new password ends the life of the hash that came with an import.
        static::updating(function (User $user) {
            if ($user->isDirty('password')) {
                $user->legacy_password = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'banned_until' => 'datetime',
            'forums_read_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'signed_out_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'is_banned' => 'boolean',
            'balance' => 'float',
            'email_on_reply' => 'boolean',
            'email_on_message' => 'boolean',
            'email_announcements' => 'boolean',
            'digest_sent_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(UserGroup::class, 'user_group_id');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_user');
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function warnings(): HasMany
    {
        return $this->hasMany(Warning::class);
    }

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isBanned(): bool
    {
        return $this->is_banned && ($this->banned_until === null || $this->banned_until->isFuture());
    }

    public function isAdmin(): bool
    {
        return app(PermissionResolver::class)->isAdmin($this);
    }

    /** Icon and name of the kinds of device a visit can come from. */
    public const DEVICES = ['mobile' => ['phone', 'Mobile'], 'tablet' => ['tablet', 'Tablet'], 'desktop' => ['monitor', 'Computer']];

    /** Online: seen a moment ago and not signed out since. */
    public function isOnline(): bool
    {
        return $this->signed_out_at === null
            && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(config('forum.online_minutes')));
    }

    public function scopeOnline($query)
    {
        return $query->whereNull('signed_out_at')->where('last_seen_at', '>', now()->subMinutes(config('forum.online_minutes')));
    }

    /** mobile | tablet | desktop, from the browser's own description of itself. */
    public static function deviceFrom(?string $agent): string
    {
        $agent = (string) $agent;

        return match (true) {
            preg_match('/iPad|Tablet|PlayBook|Silk|Android(?!.*Mobile)/i', $agent) === 1 => 'tablet',
            preg_match('/Mobi|iPhone|iPod|Android|Windows Phone|Opera Mini|IEMobile/i', $agent) === 1 => 'mobile',
            default => 'desktop',
        };
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null;
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    /** Stable hue for the letter avatar. */
    public function avatarHue(): int
    {
        return (int) (crc32($this->username) % 360);
    }

    public function initial(): string
    {
        return mb_strtoupper(mb_substr($this->username, 0, 1));
    }

    /** Title shown under the username: custom title, staff badge, the title earned by post count, else the group name. */
    public function displayTitle(): string
    {
        return $this->custom_title
            ?: ($this->group?->badge
            ?: (app(Rewards::class)->titleFor((int) $this->post_count)
            ?: ($this->group?->name ?? '')));
    }
}
