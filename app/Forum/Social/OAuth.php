<?php

namespace App\Forum\Social;

use App\Forum\Settings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * "Sign in with …" through the OAuth 2 authorisation-code flow. A provider is
 * offered as soon as its client id and secret are entered in the settings.
 */
class OAuth
{
    public const PROVIDERS = [
        'google' => [
            'label' => 'Google',
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'scope' => 'openid email profile',
        ],
        'github' => [
            'label' => 'GitHub',
            'authorize' => 'https://github.com/login/oauth/authorize',
            'token' => 'https://github.com/login/oauth/access_token',
            'scope' => 'read:user user:email',
        ],
    ];

    public function __construct(protected Settings $settings) {}

    /** @return array<string, string> provider key => label, only the configured ones */
    public function available(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $key => $provider) {
            if (filled($this->settings->get("oauth.{$key}_client_id")) && filled($this->settings->get("oauth.{$key}_client_secret"))) {
                $out[$key] = $provider['label'];
            }
        }

        return $out;
    }

    public function redirectUrl(string $provider, string $state): string
    {
        return self::PROVIDERS[$provider]['authorize'].'?'.http_build_query([
            'client_id' => $this->settings->get("oauth.{$provider}_client_id"),
            'redirect_uri' => route('social.callback', $provider),
            'response_type' => 'code',
            'scope' => self::PROVIDERS[$provider]['scope'],
            'state' => $state,
        ]);
    }

    /**
     * Exchange the code for the member's identity.
     *
     * @return array{id: string, email: string, name: string}|null null when the provider refuses or the address is not verified
     */
    public function identity(string $provider, string $code): ?array
    {
        try {
            $token = Http::asForm()->acceptJson()->timeout(15)->post(self::PROVIDERS[$provider]['token'], [
                'client_id' => $this->settings->get("oauth.{$provider}_client_id"),
                'client_secret' => $this->settings->get("oauth.{$provider}_client_secret"),
                'code' => $code,
                'redirect_uri' => route('social.callback', $provider),
                'grant_type' => 'authorization_code',
            ])->json('access_token');

            if (blank($token)) {
                return null;
            }

            return $provider === 'google' ? $this->google($token) : $this->github($token);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function google(string $token): ?array
    {
        $info = Http::withToken($token)->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo')->json();

        if (blank($info['sub'] ?? null) || blank($info['email'] ?? null) || ! ($info['email_verified'] ?? false)) {
            return null;
        }

        return ['id' => (string) $info['sub'], 'email' => (string) $info['email'], 'name' => (string) ($info['name'] ?? '')];
    }

    protected function github(string $token): ?array
    {
        $http = fn () => Http::withToken($token)->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'forum'])->timeout(15);
        $info = $http()->get('https://api.github.com/user')->json();
        $emails = $http()->get('https://api.github.com/user/emails')->json();

        $primary = collect(is_array($emails) ? $emails : [])->first(fn ($row) => ($row['primary'] ?? false) && ($row['verified'] ?? false));

        if (blank($info['id'] ?? null) || ! $primary) {
            return null;
        }

        return ['id' => (string) $info['id'], 'email' => (string) $primary['email'], 'name' => (string) ($info['login'] ?? '')];
    }
}
