<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function index()
    {
        return view('admin.groups.index', ['groups' => UserGroup::withCount('users')->orderBy('priority')->get()]);
    }

    public function create()
    {
        return view('admin.groups.form', ['group' => new UserGroup(['permissions' => []])]);
    }

    public function store(Request $request)
    {
        UserGroup::create($this->validated($request));

        return redirect()->route('admin.groups.index')->with('success', __('The user group has been created.'));
    }

    public function edit(UserGroup $group)
    {
        return view('admin.groups.form', compact('group'));
    }

    public function update(Request $request, UserGroup $group)
    {
        $group->update($this->validated($request));

        return redirect()->route('admin.groups.index')->with('success', __('The user group has been updated.'));
    }

    public function destroy(UserGroup $group)
    {
        if ($group->isSystem()) {
            return back()->with('error', __('System groups cannot be deleted.'));
        }
        if (User::where('user_group_id', $group->id)->exists()) {
            return back()->with('error', __('Move the members of this group to another group first.'));
        }

        $group->delete();

        return redirect()->route('admin.groups.index')->with('success', __('The user group has been deleted.'));
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'badge' => ['nullable', 'string', 'max:40'],
            'banner_style' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(UserGroup::BANNERS))],
            'banner_icon' => ['nullable', \Illuminate\Validation\Rule::in(UserGroup::BANNER_ICONS)],
            'banner_color2' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:10000'],
            // Empty: the general number from the settings. 0: no limit.
            'conversation_quota' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $data['conversation_quota'] = filled($data['conversation_quota'] ?? null) ? (int) $data['conversation_quota'] : null;

        $catalogue = collect(config('forum.permissions'))->collapse()->keys()->all();

        $data['priority'] ??= 0;
        $data['is_staff'] = $request->boolean('is_staff');
        $data['permissions'] = collect((array) $request->input('perms', []))
            ->only($catalogue)
            ->filter(fn ($value) => in_array($value, ['allow', 'never'], true))
            ->all();

        return $data;
    }
}
