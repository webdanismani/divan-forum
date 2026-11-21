<?php

namespace App\Http\Controllers;

use App\Forum\NodeTree;
use App\Forum\Promotions;
use App\Http\Controllers\Concerns\ChecksVisibility;
use App\Models\PromotionOption;
use App\Models\Thread;
use App\Models\ThreadPromotion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The owner of a thread pays from their balance to pin, highlight or bump it. */
class PromotionController extends Controller
{
    use ChecksVisibility;

    public function show(Request $request, Thread $thread, Promotions $promotions)
    {
        $node = $this->promotable($request, $thread);

        return view('threads.promote', [
            'thread' => $thread,
            'node' => $node,
            'breadcrumbs' => [...app(NodeTree::class)->ancestors($node), $node],
            'options' => $promotions->options($thread),
            'history' => ThreadPromotion::where('thread_id', $thread->id)->latest('id')->limit(10)->get(),
            'balance' => (float) $request->user()->balance,
        ]);
    }

    public function buy(Request $request, Thread $thread, Promotions $promotions)
    {
        $this->promotable($request, $thread);

        $data = $request->validate(['option_id' => ['required', Rule::exists('promotion_options', 'id')->where('is_active', true)]]);
        $promotions->buy($thread, $request->user(), PromotionOption::findOrFail($data['option_id']));

        return redirect()->route('threads.show', $thread)->with('success', __('Your thread has been promoted.'));
    }

    protected function promotable(Request $request, Thread $thread)
    {
        abort_unless(setting('wallet.enabled'), 404);
        $node = $this->visibleThreadNode($thread);
        abort_unless($thread->isVisible() && ($thread->user_id === $request->user()->id || Gate::allows('admin')), 403);

        return $node;
    }
}
