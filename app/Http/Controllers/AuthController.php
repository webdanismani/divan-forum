<?php

namespace App\Http\Controllers;

use App\Forum\Import\LegacyPassword;
use App\Forum\Security\Bans;
use App\Forum\Security\Devices;
use App\Forum\Security\TwoFactor;
use App\Forum\Social\OAuth;
use App\Forum\Spam\Guard;
use App\Models\ApiToken;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Seconds a member has to enter the second step after the password was accepted. */
    protected const CHALLENGE_SECONDS = 600;

    public function __construct(protected Bans $bans, protected TwoFactor $twoFactor) {}

    public function showLogin(OAuth $oauth)
    {
        return view('auth.login', ['providers' => $oauth->available()]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $field = str_contains($data['login'], '@') ? 'email' : 'username';
        $user = User::where($field, $data['login'])->first();

        // The route limits guesses per address; this one limits them per account, so that many addresses together
        // do not get further either. An address the member has signed in from before is never held up by it:
        // a stranger cannot lock the owner out. Unknown names are counted the same way, so the answer gives nothing away.
        $limit = 'login:'.sha1(Str::lower($data['login']));
        $known = $user && DB::table('ip_logs')->where('user_id', $user->id)->where('ip', $request->ip())->where('action', 'login')->exists();
        if (! $known && RateLimiter::tooManyAttempts($limit, 10)) {
            throw ValidationException::withMessages(['login' => __('Too many sign-in attempts. Please try again in :minutes minutes.', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($limit) / 60))])]);
        }

        if (! $user || ! (Hash::check($data['password'], $user->password) || $this->acceptLegacyPassword($user, $data['password']))) {
            RateLimiter::hit($limit, 900);

            throw ValidationException::withMessages(['login' => __('These credentials do not match our records.')]);
        }
        RateLimiter::clear($limit);

        return $this->signIn($request, $user, $request->boolean('remember'));
    }

    // ---- Second step ------------------------------------------------------------

    public function showChallenge(Request $request)
    {
        return $this->pendingUser($request) ? view('auth.two-factor') : redirect()->route('login');
    }

    public function challenge(Request $request)
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login');
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);

        // Six digits are few: wrong codes are counted per account, whatever address they come from.
        $limit = 'two-factor:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, 5)) {
            throw ValidationException::withMessages(['code' => __('Too many wrong codes. Please try again in :minutes minutes.', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($limit) / 60))])]);
        }
        if (! $this->twoFactor->check($user, $data['code'])) {
            RateLimiter::hit($limit, 900);

            throw ValidationException::withMessages(['code' => __('This code is not valid. Try the next code from your app, or a recovery code.')]);
        }
        RateLimiter::clear($limit);

        $remember = (bool) ($request->session()->get('two_factor')['remember'] ?? false);
        $request->session()->forget('two_factor');

        return $this->complete($request, $user, $remember);
    }

    // ---- Registration -------------------------------------------------------------

    public function showRegister(OAuth $oauth)
    {
        abort_unless(setting('registration.enabled'), 403, __('Registration is currently closed.'));

        return view('auth.register', ['providers' => $oauth->available()]);
    }

    public function register(Request $request, Guard $guard)
    {
        abort_unless(setting('registration.enabled'), 403, __('Registration is currently closed.'));
        $guard->check($request);

        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[\p{L}\p{N}_-]+$/u', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'terms' => ['accepted'],
        ], [
            'username.regex' => __('Usernames may only contain letters, numbers, dashes and underscores.'),
        ]);

        if ($this->bans->emailBanned($data['email']) || $guard->isKnownSpammer($data['email'], $request->ip())) {
            throw ValidationException::withMessages(['email' => __('This registration was refused by the spam filter. Contact the administrators if you think this is a mistake.')]);
        }

        $user = User::create([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
            'user_group_id' => UserGroup::system('registered')->id,
            'last_seen_at' => now(),
            'registration_ip' => $request->ip(),
        ]);
        $this->bans->log($user, $request->ip(), 'register');
        event('forum.user.registered', [$user]);

        Auth::login($user);
        $request->session()->regenerate();

        if (setting('registration.verify_email')) {
            return redirect()->route('verification.notice')->with(...VerificationController::deliver($user));
        }

        return redirect()->route('welcome')->with('success', __('Welcome aboard, :name!', ['name' => $user->username]));
    }

    // ---- "Sign in with ..." ----------------------------------------------------------

    public function socialRedirect(Request $request, string $provider, OAuth $oauth)
    {
        abort_unless(isset($oauth->available()[$provider]), 404);

        $state = Str::random(40);
        $request->session()->put('oauth_state', $state);

        return redirect()->away($oauth->redirectUrl($provider, $state));
    }

    public function socialCallback(Request $request, string $provider, OAuth $oauth)
    {
        abort_unless(isset($oauth->available()[$provider]), 404);

        $state = (string) $request->session()->pull('oauth_state');
        if ($state === '' || ! hash_equals($state, (string) $request->query('state')) || blank($request->query('code'))) {
            return redirect()->route('login')->with('error', __('Signing in was cancelled or took too long. Please try again.'));
        }

        $identity = $oauth->identity($provider, (string) $request->query('code'));
        if (! $identity) {
            return redirect()->route('login')->with('error', __('We could not get a verified e-mail address from :provider.', ['provider' => OAuth::PROVIDERS[$provider]['label']]));
        }

        $account = SocialAccount::where('provider', $provider)->where('provider_id', $identity['id'])->first();
        $user = $account ? User::find($account->user_id) : User::where('email', $identity['email'])->first();

        if (! $user) {
            if (! setting('registration.enabled') || $this->bans->emailBanned($identity['email'])) {
                return redirect()->route('login')->with('error', __('Registration is currently closed.'));
            }

            $user = User::create([
                'username' => $this->freeUsername($identity['name'] ?: Str::before($identity['email'], '@')),
                'email' => $identity['email'],
                'password' => Str::random(40),
                'email_verified_at' => now(), // the provider vouches for the address
                'user_group_id' => UserGroup::system('registered')->id,
                'last_seen_at' => now(),
                'registration_ip' => $request->ip(),
            ]);
            $this->bans->log($user, $request->ip(), 'register');
            event('forum.user.registered', [$user]);
        } elseif (! $account && ! $user->isVerified()) {
            // A local account with this address that nobody ever confirmed: whoever registered it may not be the owner
            // of the address. Before it is handed to the person the provider vouches for, every other way in is closed.
            $user->forceFill(['password' => Str::random(40), 'email_verified_at' => now()])->save();
            $this->twoFactor->disable($user);
            ApiToken::where('user_id', $user->id)->delete();
            app(Devices::class)->endAll($user);
        } elseif (! $user->isVerified() && Str::lower($user->email) === Str::lower($identity['email'])) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        SocialAccount::firstOrCreate(['provider' => $provider, 'provider_id' => $identity['id']], ['user_id' => $user->id]);

        return $this->signIn($request, $user, true);
    }

    public function logout(Request $request)
    {
        // Off the online list at once, not when the usual span runs out.
        $request->user()?->forceFill(['signed_out_at' => now(), 'last_seen_at' => now()])->saveQuietly();
        Cache::forget('forum.footer.online');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('forum.index');
    }

    // ---------------------------------------------------------------------

    /** The password (or the provider) was accepted: ask for the second step when the member uses one. */
    protected function signIn(Request $request, User $user, bool $remember)
    {
        if ($this->twoFactor->enabled($user)) {
            $request->session()->put('two_factor', ['user' => $user->id, 'remember' => $remember, 'at' => now()->getTimestamp()]);

            return redirect()->route('two-factor.challenge');
        }

        return $this->complete($request, $user, $remember);
    }

    protected function complete(Request $request, User $user, bool $remember)
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $this->bans->log($user, $request->ip(), 'login');

        return redirect()->intended(route('forum.index'));
    }

    /** A member imported from another forum package: the old hash is checked once, then replaced by ours. */
    protected function acceptLegacyPassword(User $user, string $password): bool
    {
        if (! $user->legacy_password || ! LegacyPassword::check($user, $password)) {
            return false;
        }

        $user->forceFill(['password' => $password])->save();

        return true;
    }

    protected function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('two_factor');

        if (! is_array($pending) || now()->getTimestamp() - (int) ($pending['at'] ?? 0) > self::CHALLENGE_SECONDS) {
            $request->session()->forget('two_factor');

            return null;
        }

        return User::find($pending['user'] ?? 0);
    }

    protected function freeUsername(string $wanted): string
    {
        $base = Str::limit(preg_replace('/[^\p{L}\p{N}_-]+/u', '', str_replace(' ', '_', $wanted)), 24, '');
        $base = mb_strlen($base) >= 3 ? $base : 'uye';
        $name = $base;

        for ($i = 0; $i < 30 && User::where('username', $name)->exists(); $i++) {
            $name = $base.random_int(10, 9999);
        }

        return $name;
    }
}
