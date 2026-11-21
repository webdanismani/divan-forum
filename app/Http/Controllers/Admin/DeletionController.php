<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ForumAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Members who asked for their account to be deleted. An administrator decides. */
class DeletionController extends Controller
{
    public function index()
    {
        return view('admin.deletions', [
            'requests' => User::with('group')->whereNotNull('deletion_requested_at')->orderBy('deletion_requested_at')->paginate(30),
        ]);
    }

    public function decide(Request $request, User $user)
    {
        abort_unless($user->deletion_requested_at, 404);
        abort_if($user->isAdmin(), 422, __('An administrator account cannot be deleted here.'));

        if ($request->input('action') !== 'delete') {
            $user->forceFill(['deletion_requested_at' => null, 'deletion_reason' => null])->save();
            $user->notify(new ForumAlert('account', $request->user(), route('account.edit'), ['text' => __('Your request to delete your account was not carried out. Your account stays as it is.')]));

            return back()->with('success', __('The request has been closed; the account stays.'));
        }

        self::anonymise($user);

        return back()->with('success', __('The account has been deleted. The member\'s posts stay, without the name.'));
    }

    /**
     * Takes everything personal off the account and closes it. Threads and posts stay where they are,
     * so conversations other members took part in keep making sense.
     */
    public static function anonymise(User $user): void
    {
        foreach (['avatar_path', 'cover_path'] as $column) {
            if ($user->{$column}) {
                Storage::disk('public')->delete($user->{$column});
            }
        }

        DB::transaction(function () use ($user) {
            $user->forceFill([
                'username' => 'silinen-uye-'.$user->id,
                'email' => 'deleted-'.$user->id.'@deleted.invalid',
                'password' => Str::random(60),
                'remember_token' => null,
                'avatar_path' => null, 'cover_path' => null, 'about' => null, 'location' => null, 'website' => null,
                'signature' => null, 'signature_html' => null, 'custom_title' => null, 'privacy' => null, 'alert_prefs' => null,
                'phone' => null, 'phone_verified_at' => null, 'last_ip' => null, 'registration_ip' => null,
                'email_on_reply' => false, 'email_on_message' => false, 'email_announcements' => false, 'email_digest' => 'none',
                'is_banned' => true, 'banned_until' => null,
                'deletion_requested_at' => null, 'deletion_reason' => null,
            ])->save();

            foreach (['profile_field_values', 'friendships', 'push_subscriptions', 'social_accounts', 'saved_replies', 'node_watches', 'thread_watches', 'bookmarks', 'username_changes'] as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                DB::table($table)->where('user_id', $user->id)->delete();
            }
            DB::table('friendships')->where('friend_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
    }
}
