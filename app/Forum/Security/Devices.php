<?php

namespace App\Forum\Security;

use App\Models\User;
use App\Notifications\ForumAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The browsers a member uses: every browser carries a random token in a cookie, and each account that signs in
 * with it is noted. That gives the member a list of open sessions, an alert when a new browser signs in,
 * and the staff a way to see accounts that share a browser.
 */
class Devices
{
    public const COOKIE = 'forum_device';

    /** Called when somebody signs in (password, social login, two-step, registration). */
    public function remember(User $user, Request $request): void
    {
        $token = (string) $request->cookie(self::COOKIE);
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            $token = Str::random(40);
        }
        Cookie::queue(self::COOKIE, $token, 60 * 24 * 730);

        $key = ['user_id' => $user->id, 'token' => $token];
        $known = DB::table('user_devices')->where($key)->exists();
        $hadOthers = ! $known && DB::table('user_devices')->where('user_id', $user->id)->exists();

        DB::table('user_devices')->updateOrInsert($key, [
            'user_agent' => Str::limit((string) $request->userAgent(), 290, ''),
            'ip' => $request->ip(),
            'last_seen_at' => now(),
        ] + ($known ? [] : ['created_at' => now()]));

        // A browser this account has not used before, while it has used others: tell the member.
        if ($hadOthers && setting('security.new_device_alerts')) {
            $about = self::describe($request->userAgent());
            $user->notify(new ForumAlert('new_device', null, route('account.edit').'#sessions', ['text' => __(
                'Your account was signed in to from a new device: :device, :ip. If this was not you, change your password and close the other sessions.',
                ['device' => $about['browser'].' / '.$about['system'], 'ip' => (string) $request->ip()],
            )]));
        }
    }

    /**
     * Open sessions of a member, newest activity first.
     *
     * @return Collection<int, array{key: string, current: bool, device: string, browser: string, system: string, ip: ?string, active: \Illuminate\Support\Carbon}>
     */
    public function sessionsOf(User $user, ?string $currentId): Collection
    {
        return DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->limit(30)->get()
            ->map(fn ($row) => [
                'key' => self::key($row->id),
                'current' => $row->id === $currentId,
                'ip' => $row->ip_address,
                'active' => now()->setTimestamp((int) $row->last_activity),
            ] + self::describe($row->user_agent));
    }

    /** Ends one session of the member (never the one that asks). */
    public function end(User $user, string $key, ?string $currentId): bool
    {
        foreach (DB::table('sessions')->where('user_id', $user->id)->pluck('id') as $id) {
            if ($id !== $currentId && hash_equals(self::key($id), $key)) {
                DB::table('sessions')->where('id', $id)->delete();
                $this->forgetRemembered($user);

                return true;
            }
        }

        return false;
    }

    /** Ends every session of the member except the one that asks. */
    public function endOthers(User $user, ?string $currentId): int
    {
        $ended = DB::table('sessions')->where('user_id', $user->id)->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))->delete();
        $this->forgetRemembered($user);

        return $ended;
    }

    /** Ends every session of the member, the asking one included (password reset, account handed to its real owner). */
    public function endAll(User $user): int
    {
        return $this->endOthers($user, null);
    }

    /** "Remember me" cookies would sign the closed browsers straight back in; a new token makes them worthless. */
    protected function forgetRemembered(User $user): void
    {
        $user->setRememberToken(Str::random(60));
        $user->save();
    }

    /** Sessions are named in forms by a digest, never by their id. */
    public static function key(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 32);
    }

    /** @return array{device: string, browser: string, system: string} */
    public static function describe(?string $agent): array
    {
        $agent = (string) $agent;
        $browser = match (true) {
            str_contains($agent, 'Edg') => 'Edge',
            str_contains($agent, 'OPR') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'YaBrowser') => 'Yandex',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Firefox') || str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'Chrome') || str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            default => __('Unknown browser'),
        };
        $system = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => __('Unknown system'),
        };

        return ['device' => User::deviceFrom($agent), 'browser' => $browser, 'system' => $system];
    }
}
