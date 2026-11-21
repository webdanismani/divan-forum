<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyEmailAddress extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('verification.verify', now()->addDays(3), [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->email),
        ]);

        return (new MailMessage)
            ->subject(__('Confirm your e-mail address'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->username]))
            ->line(__('Please confirm your e-mail address to finish setting up your account at :board.', ['board' => setting('board.name')]))
            ->action(__('Confirm e-mail address'), $url)
            ->line(__('If you did not create an account, you can ignore this message.'));
    }
}
