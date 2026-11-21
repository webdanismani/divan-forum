<?php

namespace App\Forum\License;

/**
 * Checks what the licence server sends.
 *
 * A token is "base64url(json) . base64url(signature)", signed with the Ed25519 key of the vendor's licence
 * server. That key is not trusted on its word: every answer carries a chain (root → intermediate → server
 * certificate) and the certificate must name exactly the server key this forum ships with. The root key is
 * kept offline by the vendor, so neither a copied licence server nor a home-made one can issue a token.
 */
class Verifier
{
    /** @var array<string, bool> chains already checked in this request */
    protected array $chains = [];

    public function configured(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached') && $this->key('root_key') !== null && $this->key('public_key') !== null;
    }

    /**
     * The contents of a token, or null when it is forged, signed by another server, expired, or its chain
     * does not lead back to the root key any more.
     *
     * @return array<string, mixed>|null
     */
    public function payload(string $token, mixed $chain, ?int $now = null): ?array
    {
        $now ??= now()->getTimestamp();
        $parts = explode('.', $token);
        if (! $this->configured() || count($parts) !== 2) {
            return null;
        }

        $signature = self::decode($parts[1]);
        if ($signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, $parts[0], $this->key('public_key'))) {
            return null;
        }

        $payload = json_decode((string) self::decode($parts[0]), true);
        if (! is_array($payload) || (int) ($payload['exp'] ?? 0) <= $now || ! $this->chain($chain, $now)) {
            return null;
        }

        return $payload;
    }

    /** Root signs the intermediate key, the intermediate key signs the certificate of the licence server. */
    public function chain(mixed $chain, ?int $now = null): bool
    {
        $now ??= now()->getTimestamp();
        if (! is_array($chain) || ! $this->configured()) {
            return false;
        }

        // The windows are part of the answer, so the same chain can be right now and wrong next week.
        $memo = hash('sha256', json_encode($chain).'|'.intdiv($now, 60).'|'.config('forum.license.root_key').'|'.config('forum.license.public_key'));

        return $this->chains[$memo] ??= $this->checkChain($chain, $now);
    }

    protected function checkChain(array $chain, int $now): bool
    {
        $intermediateJson = base64_decode((string) ($chain['ara'] ?? ''), true);
        $certificateJson = base64_decode((string) ($chain['sert'] ?? ''), true);
        $intermediateSignature = self::hex($chain['ara_imza'] ?? null, SODIUM_CRYPTO_SIGN_BYTES);
        $certificateSignature = self::hex($chain['sert_imza'] ?? null, SODIUM_CRYPTO_SIGN_BYTES);

        if ($intermediateJson === false || $certificateJson === false || $intermediateSignature === null || $certificateSignature === null
            || ! sodium_crypto_sign_verify_detached($intermediateSignature, $intermediateJson, $this->key('root_key'))) {
            return false;
        }

        $intermediate = json_decode($intermediateJson, true);
        if (! is_array($intermediate) || ($intermediate['t'] ?? '') !== 'ara'
            || $now < (int) ($intermediate['nb'] ?? PHP_INT_MAX) || $now > (int) ($intermediate['na'] ?? 0)) {
            return false;
        }

        $intermediateKey = self::hex($intermediate['p'] ?? null, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        if ($intermediateKey === null || ! sodium_crypto_sign_verify_detached($certificateSignature, $certificateJson, $intermediateKey)) {
            return false;
        }

        $certificate = json_decode($certificateJson, true);
        if (! is_array($certificate) || ($certificate['t'] ?? '') !== 'kurulum' || ($certificate['as'] ?? '') !== ($intermediate['s'] ?? '-')
            || $now < (int) ($certificate['nb'] ?? PHP_INT_MAX) || $now > (int) ($certificate['na'] ?? 0)) {
            return false;
        }

        // The certificate must vouch for our licence server, not for somebody else's.
        return hash_equals(bin2hex($this->key('public_key')), strtolower((string) ($certificate['p'] ?? '')));
    }

    /** A pinned key from the configuration as raw bytes. */
    protected function key(string $name): ?string
    {
        return self::hex(config('forum.license.'.$name), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
    }

    protected static function hex(mixed $value, int $length): ?string
    {
        $bytes = is_string($value) && ctype_xdigit($value) && strlen($value) % 2 === 0 ? hex2bin($value) : false;

        return is_string($bytes) && strlen($bytes) === $length ? $bytes : null;
    }

    protected static function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
