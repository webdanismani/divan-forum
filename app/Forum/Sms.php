<?php

namespace App\Forum;

use App\Models\PhoneVerification;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phone verification by text message. Three ways to send: NetGSM, Twilio,
 * or any provider that takes a plain HTTP request (the address is a
 * template with {phone} and {message}).
 */
class Sms
{
    protected const CODE_MINUTES = 10;

    protected const MAX_ATTEMPTS = 5;

    protected const MAX_PER_DAY = 4;

    /** Verification messages the whole board sends in a day, whoever asks. */
    protected const MAX_BOARD_PER_DAY = 500;

    public function __construct(protected Settings $settings) {}

    public function enabled(): bool
    {
        return match ($this->settings->get('sms.provider')) {
            'netgsm' => filled($this->settings->get('sms.netgsm_username')) && filled($this->settings->get('sms.netgsm_password')) && filled($this->settings->get('sms.netgsm_sender')),
            'twilio' => filled($this->settings->get('sms.twilio_sid')) && filled($this->settings->get('sms.twilio_token')) && filled($this->settings->get('sms.twilio_from')),
            'http' => filled($this->settings->get('sms.http_url')),
            'log' => true,
            default => false,
        };
    }

    /** Digits only, with the country code: "0532 123 45 67" becomes "905321234567". */
    public function normalize(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $country = preg_replace('/\D+/', '', (string) $this->settings->get('sms.country_code')) ?: '90';

        if (str_starts_with(trim($phone), '+') || str_starts_with($digits, '00')) {
            $digits = preg_replace('/^00/', '', $digits);
        } elseif (str_starts_with($digits, '0')) {
            $digits = $country.substr($digits, 1);
        } elseif (! str_starts_with($digits, $country) || strlen($digits) <= 10) {
            $digits = $country.$digits;
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    public function send(string $phone, string $message): bool
    {
        try {
            return match ($this->settings->get('sms.provider')) {
                'netgsm' => $this->viaNetgsm($phone, $message),
                'twilio' => Http::timeout(12)->asForm()
                    ->withBasicAuth((string) $this->settings->get('sms.twilio_sid'), (string) $this->settings->get('sms.twilio_token'))
                    ->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode((string) $this->settings->get('sms.twilio_sid')).'/Messages.json', [
                        'To' => '+'.$phone,
                        'From' => (string) $this->settings->get('sms.twilio_from'),
                        'Body' => $message,
                    ])->successful(),
                'http' => $this->viaGateway($phone, $message),
                'log' => (Log::info("SMS to +$phone: $message") ?? true),
                default => false,
            };
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * NetGSM SMS API v3 (the interface of NetGSM's own PHP package): a token
     * from /auth/v3/token, then one message to /sms/v3/send.
     */
    protected function viaNetgsm(string $phone, string $message, bool $retry = true): bool
    {
        $base = 'https://ext-api.netgsm.com.tr';
        $cacheKey = 'forum.sms.netgsm.'.sha1((string) $this->settings->get('sms.netgsm_username'));

        $token = Cache::get($cacheKey);
        if (! is_string($token) || $token === '') {
            $auth = Http::timeout(12)->acceptJson()->asJson()->post($base.'/auth/v3/token', [
                'username' => (string) $this->settings->get('sms.netgsm_username'),
                'password' => (string) $this->settings->get('sms.netgsm_password'),
                'scope' => 'sms',
            ]);
            $token = $auth->json('access_token');
            if (! $auth->successful() || ! is_string($token) || $token === '') {
                return false;
            }
            Cache::put($cacheKey, $token, max(60, (int) $auth->json('expires_in', 300) - 60));
        }

        $response = Http::timeout(12)->acceptJson()->asJson()->withToken($token)->post($base.'/sms/v3/send', [
            'sender' => (string) $this->settings->get('sms.netgsm_sender'),
            'messages' => [['recipient' => '+'.$phone, 'text' => $message]],
            'encoding' => 'TURKISH',
        ]);

        // The token was revoked or ran out early: ask for a new one, once.
        if ($response->status() === 401 && $retry) {
            Cache::forget($cacheKey);

            return $this->viaNetgsm($phone, $message, false);
        }

        return $response->successful() && (int) $response->json('accepted', 1) > 0;
    }

    protected function viaGateway(string $phone, string $message): bool
    {
        $url = strtr((string) $this->settings->get('sms.http_url'), [
            '{phone}' => rawurlencode($phone),
            '{local}' => rawurlencode(substr($phone, -10)), // without the country code
            '{message}' => rawurlencode($message),
        ]);
        $response = Http::timeout(12)->get($url);
        $expected = trim((string) $this->settings->get('sms.http_success'));

        return $response->successful() && ($expected === '' || str_contains($response->body(), $expected));
    }

    // ---- Verification -------------------------------------------------------------------------

    public function start(User $user, string $input): void
    {
        $phone = $this->normalize($input);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => __('This does not look like a phone number.')]);
        }
        if (User::where('phone', $phone)->whereNotNull('phone_verified_at')->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages(['phone' => __('This number already belongs to another account.')]);
        }

        $pending = PhoneVerification::where('user_id', $user->id)->first();
        $today = $pending && $pending->updated_at?->isToday() ? $pending->sent_count : 0;
        if ($today >= self::MAX_PER_DAY) {
            throw ValidationException::withMessages(['phone' => __('Too many codes were requested today. Try again tomorrow.')]);
        }
        // Counted apart from the pending row (which goes away when the number is removed): per member, per number
        // and for the whole board, so that nobody runs up the bill or floods somebody else's phone.
        $day = now()->format('Ymd');
        foreach (['sms:u:'.$user->id.':'.$day => self::MAX_PER_DAY, 'sms:n:'.sha1($phone).':'.$day => self::MAX_PER_DAY, 'sms:all:'.$day => self::MAX_BOARD_PER_DAY] as $counter => $most) {
            Cache::add($counter, 0, 90000);
            if ((int) Cache::increment($counter) > $most) {
                throw ValidationException::withMessages(['phone' => __('Too many codes were requested today. Try again tomorrow.')]);
            }
        }

        $code = (string) random_int(100000, 999999);
        if (! $this->send($phone, __(':code is your :board verification code.', ['code' => $code, 'board' => $this->settings->get('board.name')]))) {
            throw ValidationException::withMessages(['phone' => __('The text message could not be sent. Please try again later.')]);
        }

        PhoneVerification::updateOrCreate(['user_id' => $user->id], [
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'sent_count' => $today + 1,
            'expires_at' => now()->addMinutes(self::CODE_MINUTES),
        ]);
    }

    public function confirm(User $user, string $code): void
    {
        $pending = PhoneVerification::where('user_id', $user->id)->first();

        if (! $pending || $pending->expires_at->isPast() || $pending->attempts >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => __('This code has expired. Request a new one.')]);
        }
        if (! Hash::check(preg_replace('/\D+/', '', $code), $pending->code_hash)) {
            $pending->increment('attempts');

            throw ValidationException::withMessages(['code' => __('This code is not correct.')]);
        }

        // The number may have been verified by another account in the meantime.
        User::where('phone', $pending->phone)->where('id', '!=', $user->id)->update(['phone' => null, 'phone_verified_at' => null]);
        $user->forceFill(['phone' => $pending->phone, 'phone_verified_at' => now()])->save();
        $pending->delete();
    }

    public function pending(User $user): ?PhoneVerification
    {
        $pending = PhoneVerification::where('user_id', $user->id)->first();

        return $pending && $pending->expires_at->isFuture() && $pending->attempts < self::MAX_ATTEMPTS ? $pending : null;
    }

    /** "+90 532 *** ** 67" for display. */
    public static function mask(string $phone): string
    {
        return '+'.substr($phone, 0, max(0, strlen($phone) - 7)).' *** ** '.substr($phone, -2);
    }
}
