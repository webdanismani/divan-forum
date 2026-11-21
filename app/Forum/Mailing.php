<?php

namespace App\Forum;

use Illuminate\Support\Facades\Mail;
use Throwable;

/** Outgoing mail is configured in the admin panel, not in .env. */
class Mailing
{
    /** Copy the mail options from the settings table into the framework configuration. */
    public static function configure(Settings $settings): void
    {
        try {
            $from = trim((string) $settings->get('mail.from_address'));
            $name = trim((string) $settings->get('mail.from_name')) ?: (string) $settings->get('board.name');
        } catch (Throwable) {
            return; // not installed yet
        }

        if ($from !== '') {
            config(['mail.from.address' => $from, 'mail.from.name' => $name]);
        }

        if ($settings->get('mail.driver') !== 'smtp' || blank($settings->get('mail.host'))) {
            return;
        }

        $encryption = (string) $settings->get('mail.encryption');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => trim((string) $settings->get('mail.host')),
            'mail.mailers.smtp.port' => (int) $settings->get('mail.port'),
            'mail.mailers.smtp.username' => $settings->get('mail.username') ?: null,
            'mail.mailers.smtp.password' => $settings->get('mail.password') ?: null,
            // Symfony Mailer: "smtps" means TLS from the first byte (465); otherwise STARTTLS is used when offered.
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.encryption' => $encryption === 'none' ? null : $encryption,
            'mail.mailers.smtp.timeout' => 15,
        ]);
    }

    public static function enabled(): bool
    {
        return config('mail.default') === 'smtp';
    }

    /** @return string|null error message, null when the message was handed to the server */
    public static function test(string $to): ?string
    {
        try {
            Mail::raw(__('This is a test message from :board. Outgoing mail works.', ['board' => setting('board.name')]), function ($message) use ($to) {
                $message->to($to)->subject(__('Test message'));
            });
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }
}
