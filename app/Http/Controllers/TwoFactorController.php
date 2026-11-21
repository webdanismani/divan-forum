<?php

namespace App\Http\Controllers;

use App\Forum\Security\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Members switch two-step sign-in on and off for their own account. */
class TwoFactorController extends Controller
{
    public function __construct(protected TwoFactor $twoFactor) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $enabled = $this->twoFactor->enabled($user);
        $secret = ! $enabled ? $this->twoFactor->secret($user) : null;

        return view('account.two-factor', [
            'user' => $user,
            'enabled' => $enabled,
            'secret' => $secret,
            'qr' => $secret ? $this->twoFactor->qrSvg($user, $secret) : '',
            'codes' => session('recovery_codes'),
            'codesLeft' => $enabled ? $this->twoFactor->recoveryCodesLeft($user) : 0,
        ]);
    }

    /** Step 1: create a secret and show it. */
    public function start(Request $request)
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        abort_if($this->twoFactor->enabled($request->user()), 403);

        $this->twoFactor->begin($request->user());

        return redirect()->route('two-factor.show');
    }

    /** Step 2: the first code proves the app was set up; from now on sign-in asks for a code. */
    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $codes = $this->twoFactor->confirm($request->user(), $data['code']);

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => __('This code is not valid. Check the time on your phone and try the next code.')]);
        }

        return redirect()->route('two-factor.show')->with('recovery_codes', $codes)->with('success', __('Two-step sign-in is on.'));
    }

    public function destroy(Request $request)
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $this->twoFactor->disable($request->user());

        return redirect()->route('two-factor.show')->with('success', __('Two-step sign-in is off.'));
    }
}
