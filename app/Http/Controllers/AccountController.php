<?php

namespace App\Http\Controllers;

use App\Forum\AlertPrefs;
use App\Forum\BbCode\Renderer;
use App\Forum\Community;
use App\Forum\Integrations\Push;
use App\Forum\Privacy;
use App\Forum\Security\TwoFactor;
use App\Forum\Sms;
use App\Models\ApiToken;
use App\Models\PhoneVerification;
use App\Models\ProfileField;
use App\Models\User;
use App\Models\UserGroup;
use App\Notifications\ForumAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    /** Signs one other browser out of the account. */
    public function endSession(Request $request, string $key)
    {
        $ended = app(\App\Forum\Security\Devices::class)->end($request->user(), $key, $request->session()->getId());

        return redirect()->to(route('account.edit').'#sessions')->with($ended ? 'success' : 'error', $ended ? __('The session has been closed.') : __('That session is no longer open.'));
    }

    /** Signs every other browser out; the password is asked for, since this is what one does after a break-in. */
    public function endOtherSessions(Request $request)
    {
        $request->validate(['current_password' => ['required', 'current_password']], ['current_password.current_password' => __('The password is not correct.')]);
        $count = app(\App\Forum\Security\Devices::class)->endOthers($request->user(), $request->session()->getId());

        return redirect()->to(route('account.edit').'#sessions')->with('success', __(':count other sessions have been closed.', ['count' => $count]));
    }

    public function edit(Request $request)
    {
        $user = $request->user();

        return view('account.edit', [
            'user' => $user,
            'fields' => ProfileField::where('is_active', true)->orderBy('display_order')->get(),
            'fieldValues' => DB::table('profile_field_values')->where('user_id', $user->id)->pluck('value', 'profile_field_id'),
            'ignored' => User::whereIn('id', app(Community::class)->ignoredIds($user))->orderBy('username')->get(),
            'twoFactor' => app(TwoFactor::class)->enabled($user),
            'apiKeys' => setting('api.enabled') && forum_can('api.use') ? ApiToken::where('user_id', $user->id)->orderBy('id')->get() : null,
            'pushKey' => app(Push::class)->enabled() ? app(Push::class)->publicKey() : null,
            'smsEnabled' => app(Sms::class)->enabled(),
            'privacyChoices' => app(Privacy::class)->editable($user),
            'alertPrefs' => app(AlertPrefs::class)->all($user),
            'canRename' => forum_can('account.rename'),
            'renameDays' => (int) setting('account.rename_days'),
            'renameWait' => $this->renameWait($user),
            'canDelete' => forum_can('account.delete') && ! $user->isAdmin(),
            'signatureLimit' => forum_can('signature.use') ? (int) setting(forum_can('signature.long') ? 'signature.long_length' : 'signature.max_length') : 0,
            'phonePending' => app(Sms::class)->pending($user),
        ]);
    }

    public function alerts(Request $request)
    {
        app(AlertPrefs::class)->save($request->user(), (array) $request->input('alerts', []));

        return redirect()->to(route('account.edit').'#alerts')->with('success', __('Your alert preferences have been saved.'));
    }

    /** The day from which the member may change the username again, or null when they may now. */
    protected function renameWait(User $user): ?Carbon
    {
        $days = (int) setting('account.rename_days');
        $last = DB::table('username_changes')->where('user_id', $user->id)->where('actor_id', $user->id)->max('created_at');
        $until = $days > 0 && $last ? Carbon::parse($last)->addDays($days) : null;

        return $until && $until->isFuture() ? $until : null;
    }

    public function rename(Request $request)
    {
        forum_authorize('account.rename');
        $user = $request->user();
        if ($this->renameWait($user)) {
            throw ValidationException::withMessages(['username' => __('You changed your username recently and have to wait before the next change.')]);
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[\p{L}\p{N}_-]+$/u', Rule::unique('users', 'username')->ignore($user->id)],
            'current_password' => ['required', 'current_password'],
        ], ['username.regex' => __('Usernames may only contain letters, numbers, dashes and underscores.')]);

        if ($data['username'] !== $user->username) {
            DB::table('username_changes')->insert(['user_id' => $user->id, 'old_name' => $user->username, 'new_name' => $data['username'], 'actor_id' => $user->id, 'created_at' => now()]);
            $user->forceFill(['username' => $data['username']])->save();
            Cache::forget('forum.footer.newest_member');
        }

        return redirect()->to(route('account.edit').'#username')->with('success', __('Your username has been changed.'));
    }

    public function requestDeletion(Request $request)
    {
        forum_authorize('account.delete');
        $user = $request->user();
        abort_if($user->isAdmin(), 403, __('An administrator account cannot be deleted here.'));
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'reason' => ['nullable', 'string', 'max:300']]);

        if (! $user->deletion_requested_at) {
            $user->forceFill(['deletion_requested_at' => now(), 'deletion_reason' => $data['reason'] ?? null])->save();
            $text = __(':member asks for their account to be deleted.', ['member' => $user->username]);
            User::where('user_group_id', UserGroup::system('admin')?->id)->limit(20)->get()
                ->each(fn (User $admin) => $admin->notify(new ForumAlert('deletion_request', $user, route('admin.deletions'), ['text' => $text])));
        }

        return redirect()->to(route('account.edit').'#delete-account')->with('success', __('Your request has been received. An administrator will carry it out.'));
    }

    public function cancelDeletion(Request $request)
    {
        $request->user()->forceFill(['deletion_requested_at' => null, 'deletion_reason' => null])->save();

        return redirect()->to(route('account.edit').'#delete-account')->with('success', __('Your request has been withdrawn. Your account stays.'));
    }

    public function privacy(Request $request)
    {
        app(Privacy::class)->save($request->user(), (array) $request->input('privacy', []));

        return redirect()->to(route('account.edit').'#privacy')->with('success', __('Your privacy choices have been saved.'));
    }

    public function sendPhoneCode(Request $request, Sms $sms)
    {
        abort_unless($sms->enabled(), 404);
        $data = $request->validate(['phone' => ['required', 'string', 'max:25']]);
        // Text messages cost money: an account that has not even confirmed its e-mail address does not get to send them.
        if (setting('registration.verify_email') && ! $request->user()->isVerified()) {
            throw ValidationException::withMessages(['phone' => __('Confirm your e-mail address before verifying a phone number.')]);
        }
        $sms->start($request->user(), $data['phone']);

        return redirect()->to(route('account.edit').'#phone')->with('success', __('We have sent you a text message with a code.'));
    }

    public function confirmPhone(Request $request, Sms $sms)
    {
        abort_unless($sms->enabled(), 404);
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $sms->confirm($request->user(), $data['code']);

        return redirect()->to(route('account.edit').'#phone')->with('success', __('Your phone number has been verified.'));
    }

    public function removePhone(Request $request)
    {
        $request->user()->forceFill(['phone' => null, 'phone_verified_at' => null])->save();
        PhoneVerification::where('user_id', $request->user()->id)->delete();

        return redirect()->to(route('account.edit').'#phone')->with('success', __('Your phone number has been removed.'));
    }

    public function createApiKey(Request $request)
    {
        abort_unless(setting('api.enabled'), 404);
        forum_authorize('api.use');

        // A key outlives sessions and password changes, so making one asks for the password.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'current_password' => ['required', 'current_password'],
        ], ['current_password.current_password' => __('The password is not correct.')]);
        abort_if(ApiToken::where('user_id', $request->user()->id)->count() >= 10, 422, __('You cannot have more than 10 API keys.'));

        // Only the hash is stored: the key itself is shown once, now.
        $key = 'mf_'.Str::random(48);
        ApiToken::create(['user_id' => $request->user()->id, 'name' => $data['name'], 'token_hash' => hash('sha256', $key)]);

        return back()->with('api_key', $key)->with('success', __('The API key has been created. Copy it now: it will not be shown again.'));
    }

    public function deleteApiKey(Request $request, int $token)
    {
        ApiToken::where('user_id', $request->user()->id)->findOrFail($token)->delete();

        return back()->with('success', __('The API key has been deleted.'));
    }

    /**
     * Site addresses in the profile text need the permission "profile.links"; disguised spellings count too.
     * The forum's own address and the ones the administrator listed are always fine.
     *
     * @param  array<string, mixed>  $data  validated input with "about" and "location"
     */
    public static function refuseLinks(array $data): void
    {
        if (forum_can('profile.links')) {
            return;
        }
        $allowed = array_merge(
            [(string) parse_url((string) config('app.url'), PHP_URL_HOST), request()->getHost()],
            preg_split('/[\s,]+/', (string) setting('profile.allowed_domains'), -1, PREG_SPLIT_NO_EMPTY),
        );
        $guard = app(\App\Forum\Security\LinkGuard::class);
        $problems = [];
        foreach (['about', 'location'] as $field) {
            if ($found = $guard->find($data[$field] ?? null, $allowed)) {
                $problems[$field] = __('Site addresses cannot be written here. Please remove ":text".', ['text' => $found]);
            }
        }
        if ($problems) {
            throw ValidationException::withMessages($problems);
        }
    }

    public function profile(Request $request, Renderer $renderer)
    {
        $data = $request->validate([
            'location' => ['nullable', 'string', 'max:80'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'about' => ['nullable', 'string', 'max:2000'],
            'signature' => ['nullable', 'string', 'max:5000'],
        ]);

        self::refuseLinks($data);

        // What a signature may hold depends on the member's groups.
        $signature = trim((string) ($data['signature'] ?? ''));
        if (! forum_can('signature.use')) {
            $signature = (string) $request->user()->signature; // left as it is; it is not shown either
        } elseif ($signature !== '') {
            $limit = (int) setting(forum_can('signature.long') ? 'signature.long_length' : 'signature.max_length');
            $problem = match (true) {
                mb_strlen($signature) > $limit => __('Your signature can be at most :count characters long.', ['count' => $limit]),
                ! forum_can('signature.images') && preg_match('/\[(img|attach|media)\b/i', $signature) === 1 => __('Your member group cannot put images in the signature.'),
                ! forum_can('signature.links') && preg_match('/\[url\b|https?:\/\/|www\./i', $signature) === 1 => __('Your member group cannot put links in the signature.'),
                default => null,
            };
            if ($problem) {
                throw ValidationException::withMessages(['signature' => $problem]);
            }
        }
        $data['signature'] = $signature !== '' ? $signature : null;
        $data['signature_html'] = filled($data['signature'] ?? null) ? $renderer->render($data['signature']) : null;

        $fields = ProfileField::where('is_active', true)->get();
        $rules = [];
        foreach ($fields as $field) {
            $rules['fields.'.$field->id] = array_filter([
                $field->is_required ? 'required' : 'nullable', 'string', 'max:255',
                $field->type === 'url' ? 'url:http,https' : null,
                $field->type === 'select' ? Rule::in($field->options ?? []) : null,
            ]);
        }
        $values = $request->validate($rules)['fields'] ?? [];

        $request->user()->update($data);
        foreach ($fields as $field) {
            $key = ['user_id' => $request->user()->id, 'profile_field_id' => $field->id];
            filled($values[$field->id] ?? null)
                ? DB::table('profile_field_values')->updateOrInsert($key, ['value' => $values[$field->id]])
                : DB::table('profile_field_values')->where($key)->delete();
        }

        return back()->with('success', __('Your profile has been updated.'));
    }

    public function avatar(Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:2048', 'dimensions:min_width=48,min_height=48,max_width=4000,max_height=4000'],
        ]);

        $user = $request->user();
        $old = $user->avatar_path;
        $user->update(['avatar_path' => $request->file('avatar')->store('avatars', 'public')]);
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return back()->with('success', __('Your avatar has been updated.'));
    }

    /** Cover photo across the top of the profile: a wide picture, stored like the avatar. */
    public function cover(Request $request)
    {
        $request->validate([
            'cover' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=600,min_height=150,max_width=6000,max_height=4000'],
        ]);

        $user = $request->user();
        $old = $user->cover_path;
        $user->update(['cover_path' => $request->file('cover')->store('covers', 'public')]);
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return back()->with('success', __('Your cover photo has been updated.'));
    }

    public function removeCover(Request $request)
    {
        $user = $request->user();
        if ($user->cover_path) {
            Storage::disk('public')->delete($user->cover_path);
            $user->update(['cover_path' => null]);
        }

        return back()->with('success', __('Your cover photo has been removed.'));
    }

    public function removeAvatar(Request $request)
    {
        $user = $request->user();
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return back()->with('success', __('Your avatar has been removed.'));
    }

    public function security(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        $changed = $user->email !== $data['email'];
        $user->email = $data['email'];
        if ($changed && setting('registration.verify_email') && ! $user->isAdmin()) {
            $user->email_verified_at = null;
        }
        $newPassword = filled($data['password'] ?? null);
        if ($newPassword) {
            $user->password = $data['password'];
        }
        $user->save();

        // A new password signs every other browser out; this one stays.
        if ($newPassword) {
            app(\App\Forum\Security\Devices::class)->endOthers($user, $request->session()->getId());
        }

        if ($changed && ! $user->isVerified()) {
            return redirect()->route('verification.notice')->with(...VerificationController::deliver($user));
        }

        return back()->with('success', __('Your account details have been updated.'));
    }

    public function preferences(Request $request)
    {
        $data = $request->validate([
            'theme' => ['nullable', Rule::in(['auto', 'light', 'dark'])],
            'locale' => ['nullable', Rule::in(['tr', 'en'])],
            'email_digest' => ['nullable', Rule::in(['none', 'daily', 'weekly'])],
        ]);
        $data['email_digest'] ??= 'none';
        // The two e-mail switches moved to the alert preferences; a form that does not carry them leaves them alone.
        if (! $request->boolean('keep_mail')) {
            $data['email_on_reply'] = $request->boolean('email_on_reply');
            $data['email_on_message'] = $request->boolean('email_on_message');
        }
        $data['email_announcements'] = $request->boolean('email_announcements');
        $request->user()->update($data);

        return back()->with('success', __('Your preferences have been saved.'))->withoutCookie('theme');
    }
}
