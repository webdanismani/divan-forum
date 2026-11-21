<?php

namespace App\Forum\Payments;

use App\Forum\Settings;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * PayTR "iFrame API" (https://dev.paytr.com/iframe-api).
 *
 * Step 1: token() asks PayTR for a token and the payment form is shown in an
 * iframe. Step 2: PayTR posts the result to the notification URL
 * (route "ads.paytr"), which is checked with verify(). A payment is only
 * treated as paid in step 2, never on the return URL.
 */
class PayTr
{
    protected const TOKEN_URL = 'https://www.paytr.com/odeme/api/get-token';

    public const FRAME_URL = 'https://www.paytr.com/odeme/guvenli/';

    public const RESIZER_URL = 'https://www.paytr.com/js/iframeResizer.min.js';

    public function __construct(protected Settings $settings) {}

    public function configured(): bool
    {
        return filled($this->merchantId()) && filled($this->key()) && filled($this->salt());
    }

    public function testMode(): bool
    {
        return (bool) $this->settings->get('ads.paytr_test_mode');
    }

    /** @return array{token?: string, error?: string} */
    public function token(Payment $payment, string $ip): array
    {
        $user = $payment->user;
        // Letters and digits only. The payment id is read back from it in the notification.
        $orderId = 'P'.$payment->id.'R'.strtoupper(Str::random(12));

        $fields = [
            'merchant_id' => $this->merchantId(),
            'user_ip' => $ip,
            'merchant_oid' => $orderId,
            'email' => $user->email,
            'payment_amount' => (int) round($payment->amount * 100),
            'user_basket' => base64_encode(json_encode([
                [Str::limit($payment->title, 90, ''), number_format($payment->amount, 2, '.', ''), 1],
            ])),
            'no_installment' => 1,
            'max_installment' => 0,
            'currency' => config('forum.ads.currency'),
            'test_mode' => $this->testMode() ? 1 : 0,
        ];
        // The token is an HMAC over exactly these fields, in this order, followed by the salt.
        $fields['paytr_token'] = base64_encode(hash_hmac('sha256', implode('', $fields).$this->salt(), $this->key(), true));

        $fields += [
            'user_name' => $user->billing_name ?: $user->username,
            'user_address' => $user->billing_address ?: '-',
            'user_phone' => $user->billing_phone ?: '-',
            'merchant_ok_url' => route('payments.show', [$payment, 'paid' => 1]),
            'merchant_fail_url' => route('payments.show', [$payment, 'failed' => 1]),
            'timeout_limit' => 30,
            'debug_on' => $this->testMode() ? 1 : 0,
            'lang' => app()->getLocale() === 'tr' ? 'tr' : 'en',
        ];

        try {
            $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, $fields)->json();
        } catch (Throwable $e) {
            report($e);

            return ['error' => __('The payment service could not be reached. Please try again in a moment.')];
        }

        if (($response['status'] ?? null) !== 'success' || empty($response['token'])) {
            return ['error' => (string) ($response['reason'] ?? __('The payment service could not be reached. Please try again in a moment.'))];
        }

        $payment->update(['reference' => $orderId, 'method' => 'card']);

        return ['token' => $response['token']];
    }

    /** Check the signature of a notification sent by PayTR. */
    public function verify(array $post): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $message = ($post['merchant_oid'] ?? '').$this->salt().($post['status'] ?? '').($post['total_amount'] ?? '');
        $expected = base64_encode(hash_hmac('sha256', $message, $this->key(), true));

        return hash_equals($expected, (string) ($post['hash'] ?? ''));
    }

    public static function paymentId(string $orderId): ?int
    {
        return preg_match('/^P(\d+)R[A-Za-z0-9]+$/', $orderId, $m) ? (int) $m[1] : null;
    }

    protected function merchantId(): string
    {
        return trim((string) $this->settings->get('ads.paytr_merchant_id'));
    }

    protected function key(): string
    {
        return trim((string) $this->settings->get('ads.paytr_merchant_key'));
    }

    protected function salt(): string
    {
        return trim((string) $this->settings->get('ads.paytr_merchant_salt'));
    }
}
