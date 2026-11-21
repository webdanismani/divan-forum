<?php

namespace App\Forum\Security;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/** Two-step sign-in with an authenticator app, plus single-use recovery codes. */
class TwoFactor
{
    public function enabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && filled($user->two_factor_secret);
    }

    /** A new secret that is not active until confirm() has seen a valid code. */
    public function begin(User $user): string
    {
        $secret = Totp::generateSecret();
        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_step' => null,
        ])->save();

        return $secret;
    }

    public function secret(User $user): ?string
    {
        try {
            return filled($user->two_factor_secret) ? Crypt::decryptString($user->two_factor_secret) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return string[]|null the recovery codes (shown once), or null when the code was wrong */
    public function confirm(User $user, string $code): ?array
    {
        $secret = $this->secret($user);
        $step = $secret ? Totp::verify($secret, $code) : null;
        if ($step === null) {
            return null;
        }

        $codes = array_map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)), range(1, 8));
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_step' => $step,
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_map(fn ($c) => Hash::make($c), $codes))),
        ])->save();

        return $codes;
    }

    /** Check a code from the app or a recovery code at sign-in. Each is accepted once. */
    public function check(User $user, string $input): bool
    {
        $secret = $this->secret($user);
        if (! $secret) {
            return false;
        }

        $step = Totp::verify($secret, $input, $user->two_factor_step);
        if ($step !== null) {
            $user->forceFill(['two_factor_step' => $step])->save();

            return true;
        }

        $input = Str::lower(trim($input));
        $hashes = $this->recoveryHashes($user);
        foreach ($hashes as $i => $hash) {
            if (strlen($input) >= 8 && Hash::check($input, $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($hashes)))])->save();

                return true;
            }
        }

        return false;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null, 'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null, 'two_factor_step' => null,
        ])->save();
    }

    public function recoveryCodesLeft(User $user): int
    {
        return count($this->recoveryHashes($user));
    }

    /** Inline SVG of the QR code that authenticator apps scan. */
    public function qrSvg(User $user, string $secret): string
    {
        $uri = Totp::uri($secret, $user->username, (string) setting('board.name'));

        try {
            $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($uri);

            return substr($svg, strpos($svg, '<svg'));
        } catch (Throwable $e) {
            report($e);

            return '';
        }
    }

    /** @return string[] */
    protected function recoveryHashes(User $user): array
    {
        try {
            return filled($user->two_factor_recovery_codes) ? (array) json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true) : [];
        } catch (Throwable) {
            return [];
        }
    }
}
