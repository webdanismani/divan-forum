<?php

namespace App\Notifications;

use App\Forum\AlertPrefs;
use App\Forum\Mailer;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Generic in-site alert (reply, mention, reaction, conversation ...).
 * Stored in the notifications table and rendered by
 * resources/views/notifications/_alert.blade.php according to "kind".
 */
class ForumAlert extends Notification
{
    public function __construct(
        public string $kind,
        public ?User $actor,
        public string $url,
        public array $extra = [],
    ) {}

    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['database'];
        }

        // Shown on the site unless the member switched this kind off; the e-mail and the browser
        // notification follow the stored alert (listeners on NotificationSent).
        if (app(AlertPrefs::class)->wants($notifiable, $this->kind, 'site')) {
            return ['database'];
        }

        // No alert on the site, but the e-mail may still be wanted.
        app(Mailer::class)->mailAlert($notifiable, $this->toArray($notifiable));

        return [];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'actor_id' => $this->actor?->id,
            'actor' => $this->actor?->username,
            'url' => $this->url,
        ] + $this->extra;
    }
}
