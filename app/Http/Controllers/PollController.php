<?php

namespace App\Http\Controllers;

use App\Forum\Polls;
use App\Http\Controllers\Concerns\ChecksVisibility;
use App\Models\Thread;
use Illuminate\Http\Request;

class PollController extends Controller
{
    use ChecksVisibility;

    public function vote(Request $request, Thread $thread, Polls $polls)
    {
        $node = $this->visibleThreadNode($thread);
        forum_authorize('poll.vote', $node);
        abort_unless($thread->poll && $thread->isVisible(), 404);

        $data = $request->validate(['options' => ['required', 'array', 'min:1', 'max:'.Polls::MAX_OPTIONS], 'options.*' => ['integer']]);
        $polls->vote($thread->poll, $request->user(), $data['options']);

        return redirect()->route('threads.show', $thread)->with('success', __('Your vote has been counted.'));
    }

    /** Moderators (or the thread starter) close a poll early or remove it. */
    public function update(Request $request, Thread $thread)
    {
        $node = $this->visibleThreadNode($thread);
        abort_unless($thread->poll, 404);
        abort_unless(forum_can('mod.editAny', $node) || $thread->user_id === $request->user()->id, 403);

        $data = $request->validate(['action' => ['required', 'in:close,delete']]);

        if ($data['action'] === 'delete') {
            forum_authorize('mod.editAny', $node);
            $thread->poll->delete();

            return redirect()->route('threads.show', $thread)->with('success', __('The poll has been removed.'));
        }

        $thread->poll->update(['closes_at' => now()]);

        return redirect()->route('threads.show', $thread)->with('success', __('The poll has been closed.'));
    }
}
