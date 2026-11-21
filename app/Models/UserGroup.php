<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserGroup extends Model
{
    protected $guarded = ['id'];

    /** Ready-made designs for the group banner next to posts and on profiles: key => label. */
    public const BANNERS = [
        'plate' => 'Plate',
        'gradient' => 'Two colours',
        'ribbon' => 'Ribbon',
        'outline' => 'Outline',
        'dark' => 'Dark plate',
        'stripe' => 'Striped',
        'shine' => 'Shining',
        'solid' => 'Plain',
    ];

    /** Icons a banner can carry (names of the icon component). */
    public const BANNER_ICONS = ['crown', 'shield', 'star', 'gem', 'award', 'bolt', 'flame', 'heart', 'key', 'check', 'wallet', 'megaphone', 'user', 'users'];

    public function bannerStyle(): string
    {
        return isset(self::BANNERS[$this->banner_style]) ? $this->banner_style : 'plate';
    }

    /** The CSS variables of the banner; only well-formed colours get through. */
    public function bannerVars(): string
    {
        $vars = '';
        foreach (['--c1' => $this->color, '--c2' => $this->banner_color2] as $name => $value) {
            if (preg_match('/^#[0-9a-fA-F]{6}$/', (string) $value)) {
                $vars .= $name.': '.$value.'; ';
            }
        }

        return trim($vars);
    }

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_staff' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }

    public static function system(string $key): self
    {
        return static::where('system_key', $key)->firstOrFail();
    }
}
