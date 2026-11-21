<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $guarded = [];

    /** The coupon for a code somebody typed, if it can still be used. */
    public static function usable(string $code): ?self
    {
        $coupon = static::where('code', strtoupper(trim($code)))->where('is_active', true)->first();

        return $coupon && (! $coupon->expires_at || $coupon->expires_at->isFuture()) && ($coupon->max_uses === null || $coupon->used_count < $coupon->max_uses) ? $coupon : null;
    }

    /**
     * Takes one use of the coupon for an order that is being created. The check and the count are one statement,
     * so two orders cannot both get the last use, and a code cannot be put on several unpaid orders at once.
     */
    public static function claim(string $code): ?self
    {
        $code = strtoupper(trim($code));
        $taken = static::where('code', $code)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses'))
            ->increment('used_count');

        return $taken ? static::where('code', $code)->first() : null;
    }

    /** Gives the use back when the order it was taken for is dropped without ever being paid. */
    public static function release(?string $code): void
    {
        if (filled($code)) {
            static::where('code', $code)->where('used_count', '>', 0)->decrement('used_count');
        }
    }

    /** The price after the discount, never below zero. */
    public function apply(float $price): float
    {
        $off = $this->percent ? $price * $this->percent / 100 : (float) $this->amount;

        return round(max(0, $price - $off), 2);
    }

    protected function casts(): array
    {
        return ['amount' => 'float', 'expires_at' => 'datetime', 'is_active' => 'boolean'];
    }
}
