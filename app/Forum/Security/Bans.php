<?php

namespace App\Forum\Security;

use App\Models\Ban;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** IP address and e-mail bans ("*" is a wildcard), and the log of addresses members used. */
class Bans
{
    protected const CACHE_KEY = 'forum.bans';

    protected ?array $rules = null;

    public function ipBanned(?string $ip): bool
    {
        return $ip !== null && $this->matches('ip', $ip);
    }

    public function emailBanned(string $email): bool
    {
        return $this->matches('email', Str::lower($email));
    }

    public function flush(): void
    {
        $this->rules = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** Remember which address a member used; one row per member, address and action per day. */
    public function log(User $user, ?string $ip, string $action): void
    {
        if (blank($ip)) {
            return;
        }

        $seen = DB::table('ip_logs')->where('user_id', $user->id)->where('ip', $ip)->where('action', $action)
            ->where('created_at', '>', now()->subDay())->exists();

        if (! $seen) {
            DB::table('ip_logs')->insert(['user_id' => $user->id, 'ip' => $ip, 'action' => $action, 'created_at' => now()]);
        }
    }

    protected function matches(string $type, string $value): bool
    {
        foreach ($this->rules()[$type] ?? [] as [$pattern, $expires]) {
            if (($expires === null || $expires > now()->getTimestamp()) && Str::is($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array<int, array{0: string, 1: int|null}>> */
    protected function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        try {
            return $this->rules = Cache::rememberForever(self::CACHE_KEY, function () {
                $rules = [];
                foreach (Ban::all() as $ban) {
                    $rules[$ban->type][] = [Str::lower($ban->value), $ban->expires_at?->getTimestamp()];
                }

                return $rules;
            });
        } catch (Throwable) {
            return $this->rules = []; // table not migrated yet
        }
    }
}
