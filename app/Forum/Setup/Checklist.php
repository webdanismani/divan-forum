<?php

namespace App\Forum\Setup;

use App\Forum\Settings;
use Illuminate\Support\Str;

/**
 * What is still to be set up, for the administrator's start page: outgoing mail, payments,
 * protection against bots and the optional services. Every item says where it is settled.
 */
class Checklist
{
    public function __construct(protected Settings $settings) {}

    /** The settings page, scrolled to the group a setting belongs to. */
    public static function settingsUrl(string $key): string
    {
        foreach (config('forum.settings') as $group => $definitions) {
            if (array_key_exists($key, $definitions)) {
                return route('admin.settings.edit').'#s-'.Str::slug($group);
            }
        }

        return route('admin.settings.edit');
    }

    /**
     * @return array<int, array{title: string, text: string, state: string, url: string}> state: done | todo | optional
     */
    public function items(): array
    {
        $get = fn (string $key) => $this->settings->get($key);
        $filled = fn (string ...$keys) => collect($keys)->every(fn ($key) => filled($get($key)));
        $item = fn (string $title, string $text, bool $done, string $key, bool $needed = true) => [
            'title' => $title, 'text' => $text, 'state' => $done ? 'done' : ($needed ? 'todo' : 'optional'), 'url' => self::settingsUrl($key),
        ];

        $paytr = $filled('ads.paytr_merchant_id', 'ads.paytr_merchant_key', 'ads.paytr_merchant_salt');
        $sells = (bool) ($get('ads.enabled') || $get('wallet.enabled'));

        $items = [
            $item('Outgoing e-mail', 'Without a mail server members get no confirmation, password or alert e-mails.', $get('mail.driver') !== 'log', 'mail.driver'),
            $item('Card payments (PayTR)', 'Needed before members can top up their balance or pay for ads by card.', $paytr, 'ads.paytr_merchant_id', $sells),
            $item('Bank transfer details', 'Shown to members who choose to pay by transfer. Empty means that way of paying is off.', $filled('ads.transfer_info'), 'ads.transfer_info', false),
            $item('Captcha on the registration form', 'Keeps automatic sign-ups out. Without it only the hidden field and the timing check protect the form.', $get('captcha.provider') !== 'none' && $filled('captcha.site_key', 'captcha.secret_key'), 'captcha.provider'),
            $item('E-mail confirmation for new members', 'New members can post only after confirming their address. Needs outgoing e-mail.', (bool) $get('registration.verify_email'), 'registration.verify_email', false),
            $item('Board name and description', 'Shown in the header, in search results and when a page is shared.', $get('board.name') !== 'Meydan Forum' && $get('board.description') !== 'Topluluğumuza hoş geldiniz.', 'board.name'),
            $item('Text messages (phone verification)', 'Lets members verify a phone number; marketplaces can require it.', $get('sms.provider') !== 'none', 'sms.provider', false),
            $item('Sign in with Google or GitHub', 'One click instead of a new password.', $filled('oauth.google_client_id', 'oauth.google_client_secret') || $filled('oauth.github_client_id', 'oauth.github_client_secret'), 'oauth.google_client_id', false),
            $item('AI helpers', 'Spam check for the first posts of new members and summaries of long threads.', $filled('ai.api_key'), 'ai.api_key', false),
            $item('Browser notifications', 'Alerts reach members even when the forum is not open.', (bool) $get('push.enabled'), 'push.enabled', false),
        ];

        if ($paytr && $get('ads.paytr_test_mode')) {
            $items[] = ['title' => 'PayTR is in test mode', 'text' => 'No real money is taken while test mode is on. Switch it off when you are ready to sell.', 'state' => 'todo', 'url' => self::settingsUrl('ads.paytr_test_mode')];
        }
        if (config('app.debug')) {
            $items[] = ['title' => 'Debug mode is on', 'text' => 'Error pages show internal details to every visitor. Set APP_DEBUG=false in the .env file.', 'state' => 'todo', 'url' => route('admin.update')];
        }
        if (! request()->isSecure() && ! app()->environment('local', 'testing')) {
            $items[] = ['title' => 'The forum is not served over HTTPS', 'text' => 'Passwords travel unprotected. Install a certificate and set APP_URL to the https address.', 'state' => 'todo', 'url' => route('admin.update')];
        }

        return $items;
    }

    /** @return array{done: int, todo: int, optional: int, total: int} */
    public function counts(): array
    {
        $states = array_count_values(array_column($this->items(), 'state')) + ['done' => 0, 'todo' => 0, 'optional' => 0];

        return $states + ['total' => array_sum($states)];
    }
}
