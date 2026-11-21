<?php

namespace App\Http\Controllers;

use App\Forum\NodeTree;
use App\Forum\Tags;
use App\Models\Tag;
use App\Models\Thread;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index(Request $request, Tags $tags, NodeTree $tree)
    {
        abort_unless($tags->enabled(), 404);

        // A tag that is only used in forums the visitor cannot open is not listed: its name alone can tell too much.
        $nodes = $tree->viewableIds($request->user());
        $open = fn ($q) => $q->whereIn('node_id', $nodes)->where('state', 'visible');

        return view('tags.index', ['tags' => Tag::where('use_count', '>', 0)->whereHas('threads', $open)->orderByDesc('use_count')->limit(150)->get()->sortBy('name')->values()]);
    }

    public function show(Request $request, Tag $tag, Tags $tags, NodeTree $tree)
    {
        abort_unless($tags->enabled(), 404);
        $user = $request->user();

        $threads = Thread::query()
            ->select('threads.*')
            ->whereIn('threads.id', $tag->threads()->select('threads.id'))
            ->whereIn('threads.node_id', $tree->viewableIds($user))
            ->where('threads.state', 'visible')
            ->when($user, fn ($q) => $q
                ->leftJoin('thread_reads as tr', fn ($join) => $join->on('tr.thread_id', '=', 'threads.id')->where('tr.user_id', $user->id))
                ->addSelect('tr.read_at'))
            ->with(['user.group', 'lastPostUser.group', 'prefix', 'node'])
            ->orderByDesc('threads.last_post_at')
            ->paginate((int) setting('board.threads_per_page'));

        return view('tags.show', compact('tag', 'threads'));
    }
}
