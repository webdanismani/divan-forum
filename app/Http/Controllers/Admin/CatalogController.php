<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reaction;
use App\Models\ThreadPrefix;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Small lookup lists: thread prefixes and reactions. */
class CatalogController extends Controller
{
    public const PREFIX_COLORS = ['blue', 'green', 'red', 'orange', 'purple', 'gray'];

    public function prefixes()
    {
        return view('admin.prefixes', ['prefixes' => ThreadPrefix::orderBy('display_order')->get(), 'colors' => self::PREFIX_COLORS]);
    }

    public function savePrefix(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', Rule::exists('thread_prefixes', 'id')],
            'title' => ['required', 'string', 'max:40'],
            'color' => ['required', Rule::in(self::PREFIX_COLORS)],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $data['display_order'] ??= 0;

        ThreadPrefix::updateOrCreate(['id' => $data['id'] ?? null], collect($data)->except('id')->all());

        return back()->with('success', __('The prefix has been saved.'));
    }

    public function deletePrefix(ThreadPrefix $prefix)
    {
        $prefix->delete();

        return back()->with('success', __('The prefix has been deleted.'));
    }

    public function reactions()
    {
        return view('admin.reactions', ['reactions' => Reaction::orderBy('display_order')->get()]);
    }

    public function saveReaction(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', Rule::exists('reactions', 'id')],
            'name' => ['required', 'string', 'max:40'],
            'emoji' => ['required', 'string', 'max:16'],
            'score' => ['required', 'integer', 'min:-1', 'max:1'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $data['display_order'] ??= 0;
        $data['is_active'] = $request->boolean('is_active');

        Reaction::updateOrCreate(['id' => $data['id'] ?? null], collect($data)->except('id')->all());

        return back()->with('success', __('The reaction has been saved.'));
    }

    public function deleteReaction(Reaction $reaction)
    {
        $reaction->delete();

        return back()->with('success', __('The reaction has been deleted.'));
    }
}
