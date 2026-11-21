<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Http\Request;
use Throwable;

class VerificationController extends Controller
{
    public function notice(Request $request)
    {
        if ($request->user()->isVerified()) {
            return redirect()->route('forum.index');
        }

        return view('auth.verify-email', ['user' => $request->user()]);
    }

    /** Link from the e-mail. The signature is checked by the "signed" middleware. */
    public function verify(Request $request, int $id, string $hash)
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->email), $hash), 403);

        if (! $user->isVerified()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return redirect()->route('forum.index')->with('success', __('Thank you, your e-mail address has been confirmed.'));
    }

    public function send(Request $request)
    {
        $user = $request->user();

        if ($user->isVerified()) {
            return redirect()->route('forum.index');
        }

        return back()->with(...self::deliver($user));
    }

    /** @return array{0: string, 1: string} flash type and message */
    public static function deliver(User $user): array
    {
        try {
            $user->notify(new VerifyEmailAddress);
        } catch (Throwable $e) {
            report($e);

            return ['error', __('The confirmation e-mail could not be sent. Please try again later.')];
        }

        return ['success', __('We have sent a confirmation link to :email.', ['email' => $user->email])];
    }
}
