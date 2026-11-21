<?php

namespace App\Forum\Spam;

use App\Forum\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Protection for the public forms (registration, password reset):
 *  - a hidden field bots like to fill in, and a minimum time to fill the form
 *  - an optional captcha (Cloudflare Turnstile or Google reCAPTCHA v2)
 *  - an optional lookup at StopForumSpam for new registrations
 */
class Guard
{
    public const HONEYPOT = 'website_url';

    protected const MIN_SECONDS = 2;

    protected const VERIFY = [
        'turnstile' => ['https://challenges.cloudflare.com/turnstile/v0/siteverify', 'cf-turnstile-response'],
        'recaptcha' => ['https://www.google.com/recaptcha/api/siteverify', 'g-recaptcha-response'],
    ];

    public function __construct(protected Settings $settings) {}

    public function provider(): ?string
    {
        $provider = (string) $this->settings->get('captcha.provider');

        return isset(self::VERIFY[$provider]) && filled($this->settings->get('captcha.site_key')) && filled($this->settings->get('captcha.secret_key'))
            ? $provider
            : null;
    }

    public function siteKey(): string
    {
        return (string) $this->settings->get('captcha.site_key');
    }

    /** Value for the hidden "form opened at" field. */
    public function stamp(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /** @throws ValidationException */
    public function check(Request $request): void
    {
        if (filled($request->input(self::HONEYPOT)) || ! $this->slowEnough((string) $request->input('form_stamp'))) {
            $this->fail(__('The form could not be verified. Please try again.'));
        }

        if ($provider = $this->provider()) {
            [$url, $field] = self::VERIFY[$provider];

            try {
                $passed = (bool) Http::asForm()->timeout(10)->post($url, [
                    'secret' => $this->settings->get('captcha.secret_key'),
                    'response' => (string) $request->input($field),
                    'remoteip' => $request->ip(),
                ])->json('success');
            } catch (Throwable $e) {
                report($e);
                $passed = false;
            }

            if (! $passed) {
                $this->fail(__('Please complete the security check.'));
            }
        }
    }

    /** True when StopForumSpam knows this e-mail address or IP address as a spammer. */
    public function isKnownSpammer(string $email, ?string $ip): bool
    {
        if (! $this->settings->get('spam.stopforumspam')) {
            return false;
        }

        try {
            $result = Http::timeout(6)->get('https://api.stopforumspam.org/api', array_filter(['json' => 1, 'email' => $email, 'ip' => $ip]))->json();
        } catch (Throwable $e) {
            report($e);

            return false; // the lookup being down must not stop registrations
        }

        return (($result['email']['appears'] ?? 0) && ($result['email']['confidence'] ?? 0) >= 50)
            || (($result['ip']['appears'] ?? 0) && ($result['ip']['confidence'] ?? 0) >= 75);
    }

    protected function slowEnough(string $stamp): bool
    {
        try {
            $openedAt = (int) Crypt::decryptString($stamp);
        } catch (Throwable) {
            return false;
        }

        $age = now()->getTimestamp() - $openedAt;

        return $age >= self::MIN_SECONDS && $age < 86400;
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['captcha' => $message]);
    }
}
