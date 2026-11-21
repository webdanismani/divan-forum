<?php

namespace App\Http\Controllers;

use App\Forum\Permissions\PermissionResolver;
use App\Forum\Warnings;
use App\Models\Post;
use App\Models\User;
use App\Models\Warning;
use App\Models\WarningType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarningController extends Controller
{
    public function store(Request $request, User $user, Warnings $warnings)
    {
        forum_authorize('mod.warn');
        abort_if($user->is($request->user()) || $user->isAdmin(), 403, __('This member cannot be warned.'));

        // Warning points can end in a ban. A moderator does not warn another member of the team, and uses the
        // warning types the administrators defined; free titles and points are for administrators.
        $admin = $request->user()->isAdmin();
        abort_if(! $admin && app(PermissionResolver::class)->can($user, 'mod.warn'), 403, __('This member cannot be warned.'));

        $data = $request->validate([
            'warning_type_id' => [$admin ? 'nullable' : 'required', Rule::exists('warning_types', 'id')],
            'title' => ['required_without:warning_type_id', 'nullable', 'string', 'max:100'],
            'points' => ['required_without:warning_type_id', 'nullable', 'integer', 'min:0', 'max:100'],
            'expiry_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'note' => ['nullable', 'string', 'max:500'],
            'post_id' => ['nullable', 'integer'],
        ]);

        $type = filled($data['warning_type_id'] ?? null) ? WarningType::find($data['warning_type_id']) : null;
        $post = filled($data['post_id'] ?? null) ? Post::where('user_id', $user->id)->find($data['post_id']) : null;

        $warnings->issue(
            $user,
            $request->user(),
            $type?->title ?? $data['title'],
            $type?->points ?? (int) $data['points'],
            $type?->expiry_days ?? (int) ($data['expiry_days'] ?? 30),
            $data['note'] ?? null,
            $post,
        );

        return back()->with('success', __('The member has been warned.'));
    }

    public function destroy(Request $request, Warning $warning, Warnings $warnings)
    {
        forum_authorize('mod.warn');
        // Nobody lifts a warning of their own; a moderator lifts only what they issued themselves.
        $actor = $request->user();
        abort_if($warning->user_id === $actor->id || (! $actor->isAdmin() && $warning->issued_by !== $actor->id), 403);
        $warnings->remove($warning, $actor);

        return back()->with('success', __('The warning has been removed.'));
    }
}
