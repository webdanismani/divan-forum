<?php

namespace App\Forum\Integrations;

use App\Forum\AlertPrefs;
use App\Forum\AlertText;
use App\Forum\Settings;
use App\Jobs\SendPush;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\ForumAlert;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Browser notifications (Web Push with VAPID). The push itself carries no
 * content: it only wakes the service worker, which then asks the forum for
 * the member's newest alert. So nothing has to be encrypted per subscriber
 * and no third-party library is needed.
 */
class Push
{
    /** Push services of the browser makers. Anything else is refused: the server posts to this address. */
    protected const HOSTS = ['fcm.googleapis.com', 'push.services.mozilla.com', 'notify.windows.com', 'push.apple.com'];

    public function __construct(protected Settings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('push.enabled') && $this->publicKey() !== null;
    }

    /** The key browsers subscribe with (base64url). The pair is created the first time it is needed. */
    public function publicKey(): ?string
    {
        return $this->keys()['public'] ?? null;
    }

    /** @return array{public: string, private: string}|null */
    protected function keys(): ?array
    {
        $public = (string) $this->settings->get('push.vapid_public');
        $private = (string) $this->settings->get('push.vapid_private');

        if ($public !== '' && $private !== '') {
            try {
                return ['public' => $public, 'private' => Crypt::decryptString($private)];
            } catch (Throwable) {
                // APP_KEY changed: the old pair is unreadable, make a new one.
            }
        }

        $pair = self::generate();
        if ($pair) {
            $this->settings->set(['push.vapid_public' => $pair['public'], 'push.vapid_private' => Crypt::encryptString($pair['private'])]);
        }

        return $pair;
    }

    /** @return array{public: string, private: string}|null */
    public static function generate(): ?array
    {
        if (! function_exists('openssl_pkey_new')) {
            return null;
        }

        $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $key = @openssl_pkey_new($options);
        $config = null;

        if (! $key) {
            // Some builds (Windows) only work with an explicit configuration file.
            $config = tempnam(sys_get_temp_dir(), 'ssl');
            file_put_contents($config, "[req]\ndefault_bits = 2048\ndistinguished_name = dn\n[dn]\n");
            $options['config'] = $config;
            $key = @openssl_pkey_new($options);
        }

        $pair = null;
        if ($key && @openssl_pkey_export($key, $pem, null, $config ? ['config' => $config] : [])) {
            $ec = openssl_pkey_get_details($key)['ec'] ?? null;
            if ($ec) {
                $point = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
                $pair = ['public' => self::base64Url($point), 'private' => $pem];
            }
        }

        if ($config) {
            @unlink($config);
        }

        return $pair;
    }

    public static function allowedEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || strlen($url) > 1000) {
            return false;
        }

        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    public function subscribe(User $user, string $endpoint): bool
    {
        if (! self::allowedEndpoint($endpoint)) {
            return false;
        }

        // One browser, one member: signing in as somebody else moves the subscription.
        PushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $endpoint)], ['user_id' => $user->id, 'endpoint' => $endpoint]);

        return true;
    }

    public function unsubscribe(User $user, string $endpoint): void
    {
        PushSubscription::where('user_id', $user->id)->where('endpoint_hash', hash('sha256', $endpoint))->delete();
    }

    /** Listener: a stored alert also wakes the member's browsers. */
    public function alertStored(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notification instanceof ForumAlert || ! $event->notifiable instanceof User) {
            return;
        }

        try {
            if ($this->settings->get('push.enabled') && app(AlertPrefs::class)->wants($event->notifiable, $event->notification->kind, 'push')
                && PushSubscription::where('user_id', $event->notifiable->id)->exists()) {
                SendPush::dispatch($event->notifiable->id);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return int number of browsers that accepted the push */
    public function notify(int $userId): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $sent = 0;
        foreach (PushSubscription::where('user_id', $userId)->get() as $subscription) {
            $status = $this->send($subscription->endpoint);

            if ($status === 404 || $status === 410) {
                $subscription->delete(); // the browser dropped the subscription
            } elseif ($status >= 200 && $status < 300) {
                $sent++;
            }
        }

        return $sent;
    }

    /** @return int HTTP status of the push service, 0 when it could not be reached */
    public function send(string $endpoint): int
    {
        $keys = $this->keys();
        if (! $keys || ! self::allowedEndpoint($endpoint)) {
            return 0;
        }

        $parts = parse_url($endpoint);

        try {
            return Http::timeout(8)->withHeaders([
                'TTL' => '86400',
                'Urgency' => 'normal',
                'Authorization' => 'vapid t='.$this->jwt($parts['scheme'].'://'.$parts['host'], $keys['private']).', k='.$keys['public'],
            ])->send('POST', $endpoint)->status();
        } catch (Throwable) {
            return 0;
        }
    }

    /** ES256 token that tells the push service who is sending. */
    public function jwt(string $audience, string $privateKey): string
    {
        $from = (string) $this->settings->get('mail.from_address');
        $payload = self::base64Url(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])).'.'.self::base64Url(json_encode([
            'aud' => $audience,
            'exp' => now()->addHours(12)->getTimestamp(),
            'sub' => $from !== '' ? 'mailto:'.$from : url('/'),
        ], JSON_UNESCAPED_SLASHES));

        openssl_sign($payload, $der, $privateKey, OPENSSL_ALGO_SHA256);

        return $payload.'.'.self::base64Url(self::rawSignature($der));
    }

    /** OpenSSL answers with a DER sequence of two integers; JWT wants them as 32 + 32 bytes. */
    public static function rawSignature(string $der): string
    {
        $offset = 2 + (ord($der[1]) > 0x80 ? ord($der[1]) - 0x80 : 0);
        $out = '';

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $out .= str_pad(ltrim(substr($der, $offset + 2, $length), "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $out;
    }

    /** What the service worker shows after it has been woken up. */
    public function latestFor(?User $user): array
    {
        $alert = $user?->unreadNotifications()->first();

        return [
            'title' => (string) $this->settings->get('board.name'),
            'body' => $alert ? AlertText::text($alert->data) : __('You have a new alert.'),
            'url' => $alert ? route('notifications.open', $alert->id) : route('notifications.index'),
            'tag' => $alert ? 'alert-'.$alert->id : 'alert',
        ];
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
