<?php

namespace App\Forum\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Accounts that look like they belong to one person: they signed in from the same browser (strong sign)
 * or from the same IP address (weak sign: a household, an office or a mobile network share one).
 */
class Duplicates
{
    /** IP addresses used by more accounts than this are networks, not people. */
    public const CROWD = 15;

    /**
     * Groups of accounts that share a browser, the most recently seen group first.
     *
     * @return array<int, array{users: \Illuminate\Support\Collection<int, User>, seen: ?string}>
     */
    public function byDevice(int $limit = 100): array
    {
        $shared = DB::table('user_devices')->select('token')->groupBy('token')->havingRaw('count(distinct user_id) > 1')->limit(2000)->pluck('token');
        if ($shared->isEmpty()) {
            return [];
        }
        $rows = DB::table('user_devices')->whereIn('token', $shared)->get(['user_id', 'token', 'last_seen_at']);

        // Union-find: two accounts on one token belong together, and so do chains of them.
        $parent = [];
        $find = function (int $id) use (&$parent, &$find): int {
            $parent[$id] ??= $id;

            return $parent[$id] === $id ? $id : ($parent[$id] = $find($parent[$id]));
        };
        foreach ($rows->groupBy('token') as $sameBrowser) {
            $first = (int) $sameBrowser->first()->user_id;
            foreach ($sameBrowser as $row) {
                $parent[$find((int) $row->user_id)] = $find($first);
            }
        }

        $groups = [];
        foreach ($rows as $row) {
            $root = $find((int) $row->user_id);
            $groups[$root]['ids'][(int) $row->user_id] = true;
            $groups[$root]['seen'] = max($groups[$root]['seen'] ?? '', (string) $row->last_seen_at);
        }
        uasort($groups, fn ($a, $b) => strcmp($b['seen'], $a['seen']));
        $groups = array_slice($groups, 0, $limit);

        $users = User::with('group')->whereIn('id', collect($groups)->flatMap(fn ($group) => array_keys($group['ids']))->all())->get()->keyBy('id');

        return array_values(array_filter(array_map(fn ($group) => [
            'users' => collect(array_keys($group['ids']))->map(fn ($id) => $users->get($id))->filter()->sortBy('id')->values(),
            'seen' => $group['seen'] ?: null,
        ], $groups), fn ($group) => $group['users']->count() > 1));
    }

    /**
     * IP addresses that several accounts registered, signed in or posted from in the last days.
     *
     * @return array<int, array{ip: string, users: \Illuminate\Support\Collection<int, User>, seen: ?string}>
     */
    public function byIp(int $days = 90, int $limit = 60): array
    {
        $ips = DB::table('ip_logs')->where('created_at', '>=', now()->subDays($days))
            ->select('ip', DB::raw('count(distinct user_id) as members'), DB::raw('max(created_at) as seen'))
            ->groupBy('ip')->havingRaw('count(distinct user_id) between 2 and '.self::CROWD)
            ->orderByDesc('seen')->limit($limit)->get();
        if ($ips->isEmpty()) {
            return [];
        }
        $pairs = DB::table('ip_logs')->whereIn('ip', $ips->pluck('ip'))->distinct()->get(['ip', 'user_id']);
        $users = User::with('group')->whereIn('id', $pairs->pluck('user_id')->unique())->get()->keyBy('id');

        return $ips->map(fn ($row) => [
            'ip' => $row->ip,
            'users' => $pairs->where('ip', $row->ip)->map(fn ($pair) => $users->get($pair->user_id))->filter()->sortBy('id')->values(),
            'seen' => $row->seen,
        ])->filter(fn ($group) => $group['users']->count() > 1)->values()->all();
    }

    /**
     * Other accounts that share a browser or IP addresses with this one, the surest first.
     *
     * @return array<int, array{user: User, device: bool, ips: int}>
     */
    public function linkedTo(User $user, int $limit = 20): array
    {
        $found = [];

        $tokens = DB::table('user_devices')->where('user_id', $user->id)->pluck('token');
        if ($tokens->isNotEmpty()) {
            foreach (DB::table('user_devices')->whereIn('token', $tokens)->where('user_id', '!=', $user->id)->distinct()->pluck('user_id') as $id) {
                $found[(int) $id] = ['device' => true, 'ips' => 0];
            }
        }

        $ips = DB::table('ip_logs')->where('user_id', $user->id)->distinct()->limit(200)->pluck('ip');
        if ($ips->isNotEmpty()) {
            $crowded = DB::table('ip_logs')->whereIn('ip', $ips)->select('ip')->groupBy('ip')->havingRaw('count(distinct user_id) > '.self::CROWD)->pluck('ip');
            $shared = DB::table('ip_logs')->whereIn('ip', $ips->diff($crowded))->where('user_id', '!=', $user->id)
                ->select('user_id', DB::raw('count(distinct ip) as ips'))->groupBy('user_id')->limit(200)->get();
            foreach ($shared as $row) {
                $found[(int) $row->user_id] = ['device' => $found[(int) $row->user_id]['device'] ?? false, 'ips' => (int) $row->ips];
            }
        }
        if (! $found) {
            return [];
        }

        uasort($found, fn ($a, $b) => [$b['device'], $b['ips']] <=> [$a['device'], $a['ips']]);
        $found = array_slice($found, 0, $limit, true);
        $users = User::with('group')->whereIn('id', array_keys($found))->get()->keyBy('id');

        $linked = [];
        foreach ($found as $id => $signs) {
            if ($member = $users->get($id)) {
                $linked[] = ['user' => $member] + $signs;
            }
        }

        return $linked;
    }

    /** How many groups of accounts share a browser (for the menu). */
    public function count(): int
    {
        return (int) DB::table('user_devices')->select('token')->groupBy('token')->havingRaw('count(distinct user_id) > 1')->get()->count();
    }
}
