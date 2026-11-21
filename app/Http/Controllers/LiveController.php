<?php

namespace App\Http\Controllers;

use App\Forum\Ai\Assistant;
use App\Forum\AlertText;
use App\Forum\Moderation\Censor;
use App\Forum\Similar;
use App\Http\Controllers\Concerns\ChecksVisibility;
use App\Models\Shout;
use App\Models\Thread;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Small JSON endpoints the page calls while it is open: live alerts, the shoutbox, thread helpers. */
class LiveController extends Controller
{
    use ChecksVisibility;

    /** Header counters and the newest unread alert, asked for every half minute. */
    public function poll(Request $request)
    {
        $user = $request->user();
        $latest = $user->unreadNotifications()->first();

        return response()->json([
            'alerts' => $user->unreadNotifications()->count(),
            'conversations' => DB::table('conversation_participants as p')
                ->join('conversations as c', 'c.id', '=', 'p.conversation_id')
                ->where('p.user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('p.last_read_at')->orWhereColumn('p.last_read_at', '<', 'c.last_message_at'))
                ->count(),
            'latest' => $latest ? ['id' => $latest->id, 'text' => AlertText::text($latest->data), 'url' => route('notifications.open', $latest->id)] : null,
        ])->header('Cache-Control', 'no-store');
    }

    // ---- Shoutbox -----------------------------------------------------------------------------

    public function shouts(Request $request)
    {
        abort_unless(setting('shoutbox.enabled'), 404);
        $after = (int) $request->query('after', 0);

        $shouts = Shout::with('user.group')->where('id', '>', $after)->latest('id')->limit(30)->get()->reverse()->values();

        return response()->json([
            'html' => view('forum._shouts', ['shouts' => $shouts])->render(),
            'last' => $shouts->last()?->id ?? $after,
        ])->header('Cache-Control', 'no-store');
    }

    public function shout(Request $request)
    {
        abort_unless(setting('shoutbox.enabled'), 404);
        forum_authorize('shoutbox.post');

        $data = $request->validate(['body' => ['required', 'string', 'max:300']]);
        $body = trim(preg_replace('/\s+/u', ' ', $data['body']));
        abort_if($body === '', 422);

        // Same text twice in a row is a stuck key or a bot.
        $last = Shout::where('user_id', $request->user()->id)->latest('id')->first();
        if (! $last || $last->body !== $body || $last->created_at->lt(now()->subMinutes(2))) {
            Shout::create(['user_id' => $request->user()->id, 'body' => app(Censor::class)->clean($body), 'created_at' => now()]);
        }

        // The box keeps a few hundred lines; older ones are of no use to anybody.
        if (random_int(1, 20) === 1 && ($cut = Shout::latest('id')->skip(500)->value('id'))) {
            Shout::where('id', '<=', $cut)->delete();
        }

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function deleteShout(Request $request, Shout $shout)
    {
        abort_unless($request->user()->group?->is_staff || $request->user()->isAdmin(), 403);
        $shout->delete();

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    // ---- Thread helpers -----------------------------------------------------------------------

    /** Existing threads that look like the title somebody is typing. */
    public function similar(Request $request, Similar $similar)
    {
        $title = (string) $request->query('title', '');

        return response()->json([
            'threads' => mb_strlen($title) < 8 ? [] : $similar->threads($title, $request->user())
                ->map(fn (Thread $thread) => ['title' => $thread->title, 'url' => route('threads.show', $thread), 'forum' => $thread->node?->title, 'replies' => $thread->reply_count])->all(),
        ]);
    }

    /** Writes (or refreshes) the AI summary of a long thread. */
    public function summarize(Request $request, Thread $thread, Assistant $assistant)
    {
        $this->visibleThreadNode($thread);
        abort_unless($assistant->enabled() && setting('ai.summaries') && $thread->state === 'visible', 404);
        abort_unless($thread->reply_count + 1 >= (int) setting('ai.summary_min_posts'), 403);

        $existing = $assistant->cachedSummary($thread);
        if ($existing && ! $assistant->summaryIsStale($thread, $existing)) {
            return back();
        }

        @set_time_limit(150);

        return $assistant->summarize($thread)
            ? redirect()->to(route('threads.show', $thread).'#summary')
            : back()->with('error', __('The summary could not be written right now. Please try again later.'));
    }
}
