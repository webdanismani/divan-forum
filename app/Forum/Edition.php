<?php

namespace App\Forum;

/**
 * Edition / licence gate.
 *
 * The open-source core runs as the "free" edition. Premium functionality
 * must be guarded with Edition::has('feature') so that it can ship as a
 * separate package later. The edition comes from config('forum.edition').
 * Licence codes (of the forum itself and of sold modules and themes) are
 * the business of App\Forum\License\Licenses; a forum without a code of
 * its own keeps working.
 */
class Edition
{
    public function __construct(protected Settings $settings) {}

    public function name(): string
    {
        return config('forum.edition') === 'free' ? 'free' : 'premium';
    }

    public function isPremium(): bool
    {
        return $this->name() === 'premium';
    }

    /** True when a licence code of the forum software itself is active on this installation. */
    public function licensed(): bool
    {
        $licenses = app(License\Licenses::class);

        return $licenses->valid($licenses->coreProduct());
    }

    /** @return array<string, string> feature key => description */
    public function premiumFeatures(): array
    {
        return config('forum.premium_features', []);
    }

    public function has(string $feature): bool
    {
        return ! array_key_exists($feature, $this->premiumFeatures()) || $this->isPremium();
    }
}
