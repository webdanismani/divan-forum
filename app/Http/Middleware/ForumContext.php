<?php

namespace App\Http\Middleware;

use App\Forum\Security\Bans;
use App\Forum\Tasks;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rejects malformed input, sets the locale, records member activity and keeps banned members out. */
class ForumContext
{
    public function handle(Request $request, Closure $next): Response
    {
        // Text that is not valid UTF-8 cannot be stored as JSON or printed safely; no browser sends it.
        $input = $request->input();
        array_walk_recursive($input, function ($value) {
            abort_if(is_string($value) && ! mb_check_encoding($value, 'UTF-8'), 400);
        });

        $user = $request->user();

        // Banned networks see nothing, but the sign-in pages stay open so that an administrator is never locked out.
        if (! $request->is('login', 'login/*', 'logout') && ! $user?->isAdmin() && app(Bans::class)->ipBanned($request->ip())) {
            abort(403, __('Access from your network has been blocked.'));
        }

        $locale = $user?->locale ?: setting('board.locale', config('app.locale'));
        if (in_array($locale, ['tr', 'en'], true)) {
            app()->setLocale($locale);
        }

        if ($user) {
            if ($user->isBanned() && ! $request->routeIs('logout')) {
                return response()->view('auth.banned', ['user' => $user], 403);
            }

            // Written at most once a minute — and at once when the member comes back after signing out or on another device.
            $device = User::deviceFrom($request->userAgent());
            if (! $user->last_seen_at || $user->signed_out_at || $user->last_device !== $device || $user->last_seen_at->lt(now()->subMinute())) {
                $user->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip(), 'last_device' => $device, 'signed_out_at' => null])->saveQuietly();
            }
        }

        return $next($request);
    }

    /** Runs after the response has been sent. */
    public function terminate(Request $request, Response $response): void
    {
        app(Tasks::class)->tick();
    }
}
