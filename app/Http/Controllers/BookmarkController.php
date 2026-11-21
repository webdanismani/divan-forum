<?php

namespace App\Http\Controllers;

use App\Forum\NodeTree;
use App\Http\Controllers\Concerns\ChecksVisibility;
use App\Models\Bookmark;
use App\Models\Post;
use Illuminate\Http\Request;

class BookmarkController extends Controller
{
    use ChecksVisibility;

    public function index(Request $request, NodeTree $tree)
    {
        $bookmarks = Bookmark::with(['post.thread.node', 'post.user.group'])
            ->where('user_id', $request->user()->id)
            // Posts in forums the member can no longer see are left out.
            ->whereHas('post.thread', fn ($q) => $q->whereIn('node_id', $tree->viewableIds($request->user()))->where('state', 'visible'))
            ->whereHas('post', fn ($q) => $q->where('state', 'visible'))
            ->latest('id')
            ->paginate(25);

        return view('bookmarks.index', compact('bookmarks'));
    }

    /** Add the bookmark, or remove it when it exists. */
    public function toggle(Request $request, Post $post)
    {
        $this->visiblePost($post);
        $key = ['user_id' => $request->user()->id, 'post_id' => $post->id];

        if (Bookmark::where($key)->delete()) {
            return back()->with('success', __('The bookmark has been removed.'));
        }

        $data = $request->validate(['note' => ['nullable', 'string', 'max:200']]);
        Bookmark::create($key + ['note' => $data['note'] ?? null]);

        return redirect()->to(url()->previous().'#post-'.$post->id)->with('success', __('The post has been added to your bookmarks.'));
    }
}
