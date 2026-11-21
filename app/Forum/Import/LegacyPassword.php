<?php

namespace App\Forum\Import;

use App\Models\User;

/**
 * Members of an imported forum keep their passwords: the old hash is kept
 * next to the account, checked once at the first sign-in and then replaced
 * by this forum's own hash.
 */
class LegacyPassword
{
    public static function check(User $user, string $password): bool
    {
        $legacy = json_decode((string) $user->legacy_password, true);
        if (! is_array($legacy) || ! is_string($legacy['hash'] ?? null) || $legacy['hash'] === '') {
            return false;
        }

        $hash = $legacy['hash'];
        $salt = (string) ($legacy['salt'] ?? '');

        return match ($legacy['scheme'] ?? '') {
            'vbulletin' => hash_equals(strtolower($hash), md5(md5($password).$salt)),
            'mybb' => hash_equals(strtolower($hash), md5(md5($salt).md5($password))),
            // SMF 2.0 uses SHA-1, SMF 2.1 bcrypt; both over the lower-case login name followed by the password.
            'smf' => strlen($hash) === 40
                ? hash_equals(strtolower($hash), sha1(mb_strtolower((string) ($legacy['name'] ?? '')).$password))
                : password_verify(mb_strtolower((string) ($legacy['name'] ?? '')).$password, $hash),
            default => false,
        };
    }
}
