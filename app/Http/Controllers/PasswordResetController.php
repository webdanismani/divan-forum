<?php

namespace App\Http\Controllers;

use App\Forum\Security\Devices;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function request()
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        // Same answer whether or not the address exists, to avoid account enumeration.
        return back()->with('success', __('If that address belongs to an account, a password reset link has been sent.'));
    }

    public function reset(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                // A reset is what one does after losing control of the account: open sessions and API keys end here.
                app(Devices::class)->endAll($user);
                ApiToken::where('user_id', $user->id)->delete();
                event(new PasswordReset($user));
            },
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('success', __('Your password has been reset. You can sign in now.'))
            : back()->withErrors(['email' => __('This password reset link is invalid or has expired.')]);
    }
}
