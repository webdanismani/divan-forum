<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $alerts = $request->user()->notifications()->paginate(25);

        return view('notifications.index', compact('alerts'));
    }

    /** The newest alerts, for the pop-over in the header. */
    public function panel(Request $request)
    {
        $alerts = $request->user()->notifications()->limit(8)->get();
        $actors = User::with('group')->whereIn('id', $alerts->pluck('data.actor_id')->filter()->unique())->get()->keyBy('id');

        return response()->view('notifications._panel', compact('alerts', 'actors'))->header('Cache-Control', 'no-store');
    }

    /** Mark one alert as read and continue to its target. */
    public function open(Request $request, string $id)
    {
        $alert = $request->user()->notifications()->findOrFail($id);
        $alert->markAsRead();

        $url = (string) ($alert->data['url'] ?? '');

        // Only addresses of this board: "https://forum.example.evil.test" starts with the board's address too.
        $root = rtrim(url('/'), '/');

        return $url === $root || str_starts_with($url, $root.'/') ? redirect()->to($url) : redirect()->route('notifications.index');
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', __('All alerts have been marked as read.'));
    }
}
