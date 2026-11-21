<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Permissions\PermissionResolver;
use App\Forum\Posting;
use App\Forum\Security\TwoFactor;
use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q'));
        $like = addcslashes($search, '%_\\').'%';

        $users = User::with('group')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('username', 'like', $like)->orWhere('email', 'like', $like)))
            ->when($request->query('filter') === 'banned', fn ($q) => $q->where('is_banned', true))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.users.index', compact('users', 'search'));
    }

    public function edit(User $user)
    {
        return view('admin.users.form', [
            'twoFactor' => app(TwoFactor::class)->enabled($user),
            'ipLog' => DB::table('ip_logs')->where('user_id', $user->id)->orderByDesc('id')->limit(15)->get(),
            'linked' => app(\App\Forum\Security\Duplicates::class)->linkedTo($user),
            'badges' => Badge::orderBy('display_order')->get(),
            'ownBadges' => DB::table('user_badges')->where('user_id', $user->id)->pluck('badge_id')->all(),
            'user' => $user->load('groups'),
            'groups' => UserGroup::where(fn ($q) => $q->whereNull('system_key')->orWhere('system_key', '!=', 'guest'))->orderBy('priority')->get(),
        ]);
    }

    public function update(Request $request, User $user, Posting $posting)
    {
        $assignable = UserGroup::where(fn ($q) => $q->whereNull('system_key')->orWhere('system_key', '!=', 'guest'))->pluck('id')->all();

        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[\p{L}\p{N}_-]+$/u', Rule::unique('users')->ignore($user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'user_group_id' => ['required', Rule::in($assignable)],
            'secondary' => ['array'],
            'secondary.*' => [Rule::in($assignable)],
            'custom_title' => ['nullable', 'string', 'max:60'],
            'password' => ['nullable', Password::defaults()],
            'ban_reason' => ['nullable', 'string', 'max:255'],
            'banned_until' => ['nullable', 'date', 'after:now'],
        ]);

        $self = $user->is($request->user());
        $ban = $request->boolean('is_banned') && ! $self;

        if ($ban !== $user->is_banned) {
            $posting->log($request->user(), $ban ? 'user.ban' : 'user.unban', 'user', $user->id, $user->username);
        }

        $user->fill([
            'username' => $data['username'],
            'email' => $data['email'],
            'custom_title' => $data['custom_title'] ?? null,
            'email_verified_at' => $request->boolean('email_verified') ? ($user->email_verified_at ?? now()) : null,
            'is_banned' => $ban,
            'ban_reason' => $ban ? ($data['ban_reason'] ?? null) : null,
            'banned_until' => $ban ? ($data['banned_until'] ?? null) : null,
        ]);

        // Administrators cannot change their own groups, to avoid locking themselves out.
        if (! $self) {
            $user->user_group_id = $data['user_group_id'];
            $user->groups()->sync(array_diff($data['secondary'] ?? [], [$data['user_group_id']]));
        }
        $newPassword = filled($data['password'] ?? null);
        if ($newPassword) {
            $user->password = $data['password'];
        }
        $user->save();

        // A password set by an administrator closes what was open on the account (the administrator's own session stays).
        if ($newPassword) {
            app(\App\Forum\Security\Devices::class)->endOthers($user, $self ? $request->session()->getId() : null);
            if (! $self) {
                \App\Models\ApiToken::where('user_id', $user->id)->delete();
            }
        }

        if ($request->boolean('reset_two_factor')) {
            app(TwoFactor::class)->disable($user);
        }

        app(PermissionResolver::class)->flush();

        return redirect()->route('admin.users.edit', $user)->with('success', __('The member has been updated.'));
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->is($request->user())) {
            return back()->with('error', __('You cannot delete your own account here.'));
        }

        $user->delete();

        return redirect()->route('admin.users.index')->with('success', __('The member has been deleted. Their content was kept.'));
    }
}
