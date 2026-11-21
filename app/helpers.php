<?php

use App\Forum\Permissions\PermissionResolver;
use App\Forum\Settings;
use App\Models\Node;
use Illuminate\Auth\AuthenticationException;

if (! function_exists('setting')) {
    /** Read a board option (see config/forum.php "settings"). */
    function setting(string $key, mixed $default = null): mixed
    {
        return app(Settings::class)->get($key, $default);
    }
}

if (! function_exists('forum_can')) {
    /** Check a forum permission for the current visitor (guests included). */
    function forum_can(string $permission, Node|int|null $node = null): bool
    {
        return app(PermissionResolver::class)->can(auth()->user(), $permission, $node);
    }
}

if (! function_exists('forum_number')) {
    /** Format a counter with the thousands separator of the current language. */
    function forum_number(int|float|null $value): string
    {
        return number_format((float) $value, 0, ',', app()->getLocale() === 'tr' ? '.' : ',');
    }
}

if (! function_exists('forum_money')) {
    /** Format an amount in the advertising currency, e.g. "1.250 ₺". */
    function forum_money(int|float|null $value): string
    {
        $turkish = app()->getLocale() === 'tr';
        $decimals = fmod((float) $value, 1.0) === 0.0 ? 0 : 2;

        return number_format((float) $value, $decimals, $turkish ? ',' : '.', $turkish ? '.' : ',').' '.config('forum.ads.currency_symbol');
    }
}

if (! function_exists('forum_authorize')) {
    /** Guests are sent to the login page, members get a 403. */
    function forum_authorize(string $permission, Node|int|null $node = null): void
    {
        if (! forum_can($permission, $node)) {
            if (auth()->guest()) {
                throw new AuthenticationException;
            }
            abort(403);
        }
    }
}
