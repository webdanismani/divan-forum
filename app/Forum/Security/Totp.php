<?php

namespace App\Forum\Security;

/** Time-based one-time passwords (RFC 6238: HMAC-SHA1, 30 second steps, 6 digits) as used by authenticator apps. */
class Totp
{
    protected const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    protected const PERIOD = 30;

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function step(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD);
    }

    public static function codeAt(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $number = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % 1_000_000;

        return str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * The time step the code belongs to (now, or one step either side to allow
     * for clock drift); null when it is wrong or was used already.
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return null;
        }

        $current = self::step($timestamp);
        foreach ([$current, $current - 1, $current + 1] as $step) {
            if (hash_equals(self::codeAt($secret, $step), $code)) {
                return $lastStep !== null && $step <= $lastStep ? null : $step;
            }
        }

        return null;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => self::PERIOD,
        ]);
    }

    public static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $text))) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
