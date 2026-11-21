<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Saved replies: texts a member keeps and puts into the editor with one click. */
class SavedReplyController extends Controller
{
    /** The page where they are managed; the editor asks for the same list as JSON. */
    public function index(Request $request)
    {
        forum_authorize('replies.saved');
        $replies = DB::table('saved_replies')->where('user_id', $request->user()->id)->orderBy('title')->get(['id', 'title', 'body']);

        if ($request->expectsJson()) {
            return response()->json(['replies' => $replies])->header('Cache-Control', 'no-store');
        }

        return view('account.replies', ['replies' => $replies, 'limit' => (int) setting('replies.max')]);
    }

    public function store(Request $request)
    {
        forum_authorize('replies.saved');
        $data = $this->validated($request);

        if (DB::table('saved_replies')->where('user_id', $request->user()->id)->count() >= (int) setting('replies.max')) {
            throw ValidationException::withMessages(['title' => __('You can keep at most :count saved replies. Delete one to add another.', ['count' => (int) setting('replies.max')])]);
        }
        DB::table('saved_replies')->insert($data + ['user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', __('The saved reply has been added.'));
    }

    public function update(Request $request, int $reply)
    {
        forum_authorize('replies.saved');
        $changed = DB::table('saved_replies')->where('id', $reply)->where('user_id', $request->user()->id)->update($this->validated($request) + ['updated_at' => now()]);
        abort_unless($changed, 404);

        return back()->with('success', __('The saved reply has been updated.'));
    }

    public function destroy(Request $request, int $reply)
    {
        abort_unless(DB::table('saved_replies')->where('id', $reply)->where('user_id', $request->user()->id)->delete(), 404);

        return back()->with('success', __('The saved reply has been deleted.'));
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'min:2', 'max:5000'],
        ]);
    }
}
