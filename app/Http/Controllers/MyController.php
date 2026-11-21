<?php

namespace App\Http\Controllers;

use App\Forum\NodeTree;
use App\Models\Post;
use App\Models\Thread;
use Illuminate\Http\Request;

/** "Mine": the member's own threads and posts, the threads they took part in, and what they have not read yet. */
class MyController extends Controller
{
    public const TABS = ['unread' => 'Unread', 'threads' => 'My threads', 'joined' => 'Threads I replied to', 'posts' => 'My posts'];

    public function __invoke(Request $request, NodeTree $tree, string $tab = 'unread')
    {
        abort_unless(isset(self::TABS[$tab]), 404);
        $user = $request->user();
        $viewable = $tree->viewableIds($user);
        $perPage = (int) setting('board.threads_per_page');

        if ($tab === 'posts') {
            $posts = Post::with('thread')->where('user_id', $user->id)->where('state', 'visible')
                ->whereHas('thread', fn ($q) => $q->whereIn('node_id', $viewable)->where('state', 'visible'))
                ->latest('id')->paginate($perPage);

            return view('forum.my', ['tab' => $tab, 'posts' => $posts, 'threads' => null]);
        }

        $threads = Thread::query()
            ->select('threads.*', 'tr.read_at')
            ->leftJoin('thread_reads as tr', fn ($join) => $join->on('tr.thread_id', '=', 'threads.id')->where('tr.user_id', $user->id))
            ->whereIn('threads.node_id', $viewable)
            ->where('threads.state', 'visible')
            ->with(['user.group', 'lastPostUser.group', 'prefix', 'node']);

        if ($tab === 'threads') {
            $threads->where('threads.user_id', $user->id);
        } elseif ($tab === 'joined') {
            $threads->where(fn ($q) => $q->whereNull('threads.user_id')->orWhere('threads.user_id', '!=', $user->id))
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('posts')->whereColumn('posts.thread_id', 'threads.id')->where('posts.user_id', $user->id)->where('posts.state', 'visible'));
        } else {
            // Unread: active since the member last marked the forums read (or within the usual span) and not opened since.
            $cutoff = now()->subDays(config('forum.unread_days'));
            if ($user->forums_read_at && $user->forums_read_at->gt($cutoff)) {
                $cutoff = $user->forums_read_at;
            }
            $threads->where('threads.last_post_at', '>', $cutoff)
                ->where(fn ($q) => $q->whereNull('tr.read_at')->orWhereColumn('tr.read_at', '<', 'threads.last_post_at'));
        }

        return view('forum.my', ['tab' => $tab, 'posts' => null, 'threads' => $threads->orderByDesc('threads.last_post_at')->paginate($perPage)]);
    }
}
