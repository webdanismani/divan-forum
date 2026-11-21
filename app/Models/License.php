<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The licence code of one product on this forum and the last answer of the licence server about it. */
class License extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'license_key' => 'encrypted',
            'chain' => 'array',
            'valid_until' => 'datetime',
            'license_ends_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    /** The code with its middle hidden, for lists. */
    public function maskedKey(): string
    {
        try {
            $key = (string) $this->license_key;
        } catch (\Throwable) {
            return '';
        }

        return strlen($key) > 10 ? substr($key, 0, 5).str_repeat('•', 8).substr($key, -4) : str_repeat('•', strlen($key));
    }
}
