<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Edition;
use App\Forum\Settings;
use App\Forum\Setup\Pending;
use App\Http\Controllers\Controller;
use App\Models\ModeratorLog;
use App\Models\Post;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Pending $pending)
    {
        $waiting = $pending->items();

        return view('admin.dashboard', [
            'acpPending' => $pending,
            // Label => [number, icon, where a click leads, whether a number above zero asks for attention].
            'stats' => [
                'Members' => [User::count(), 'users', route('admin.users.index'), false],
                'Threads' => [Thread::where('state', 'visible')->count(), 'list', route('admin.nodes.index'), false],
                'Posts' => [Post::where('state', 'visible')->count(), 'message', route('whats-new'), false],
                'Online now' => [User::online()->count(), 'eye', route('members.index'), false],
                'Open reports' => [$waiting['reports']['count'], 'alert', $waiting['reports']['url'], true],
                'Awaiting approval' => [$waiting['approval']['count'], 'check', $waiting['approval']['url'], true],
            ],
            'charts' => [
                'New members' => $this->daily(User::query()),
                'New threads' => $this->daily(Thread::where('state', 'visible')),
                'New posts' => $this->daily(Post::where('state', 'visible')),
            ],
            'newMembers' => User::with('group')->latest('id')->limit(8)->get(),
            'recentLogs' => ModeratorLog::with('user')->latest('id')->limit(8)->get(),
            'system' => [
                'PHP' => PHP_VERSION,
                'Laravel' => app()->version(),
                'Database' => config('database.default'),
                'Environment' => app()->environment(),
                'Debug mode' => config('app.debug') ? 'on' : 'off',
            ],
        ]);
    }

    /** Rows created per day for the last 30 days, oldest first. @return array<string, int> */
    protected function daily($query): array
    {
        $counts = $query->where('created_at', '>=', today()->subDays(29))
            ->selectRaw('date(created_at) as day, count(*) as total')->groupBy('day')->pluck('total', 'day');

        $out = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = today()->subDays($i)->toDateString();
            $out[$day] = (int) ($counts[$day] ?? 0);
        }

        return $out;
    }

    public function logs()
    {
        return view('admin.logs', ['logs' => ModeratorLog::with('user')->latest('id')->paginate(40)]);
    }
}
