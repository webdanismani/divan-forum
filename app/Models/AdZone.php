<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A fixed place in the templates where banners are shown (see App\Forum\Ads). */
class AdZone extends Model
{
    public const POSITIONS = ['top', 'sidebar', 'thread', 'bottom', 'links'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['prices' => 'array', 'is_active' => 'boolean'];
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class, 'zone_id');
    }

    /** @return array<int, float> days => price, shortest period first */
    public function priceList(): array
    {
        $list = [];
        foreach ($this->prices ?? [] as $row) {
            if (($row['days'] ?? 0) > 0) {
                $list[(int) $row['days']] = (float) $row['price'];
            }
        }
        ksort($list);

        return $list;
    }

    public function priceFor(int $days): ?float
    {
        return $this->priceList()[$days] ?? null;
    }

    /** A text zone sells links (a title and an address), not banners. */
    public function isText(): bool
    {
        return $this->kind === 'text';
    }

    public function size(): string
    {
        return $this->isText() ? __('Text link') : $this->width.'×'.$this->height;
    }
}
