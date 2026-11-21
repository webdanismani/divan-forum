<?php

namespace App\Http\Middleware;

use App\Forum\Permissions\PermissionResolver;
use App\Forum\Security\Bans;
use App\Models\ApiToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * API requests carry "Authorization: Bearer <key>". With a key the request
 * runs as the member who owns it, with exactly that member's permissions;
 * without one it runs as a guest.
 */
class ApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(setting('api.enabled'), 404);
        app()->setLocale((string) setting('board.locale', config('app.locale')));

        // A banned network is banned here as well, with or without a key.
        if (app(Bans::class)->ipBanned($request->ip())) {
            return response()->json(['message' => 'Access from your network has been blocked.'], 403);
        }

        $key = $request->bearerToken();
        if ($key !== null) {
            $token = ApiToken::where('token_hash', hash('sha256', $key))->first();
            $user = $token ? User::find($token->user_id) : null;

            if (! $user || $user->isBanned() || ! app(PermissionResolver::class)->can($user, 'api.use')) {
                return response()->json(['message' => 'Invalid API key.'], 401);
            }

            Auth::setUser($user);
            if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
                $token->forceFill(['last_used_at' => now()])->save();
            }
        }

        return $next($request);
    }
}
