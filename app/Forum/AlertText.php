<?php

namespace App\Forum;

/** One sentence and an icon for a stored alert; shared by the alerts page and the e-mails. */
class AlertText
{
    public const ICONS = [
        'reply' => 'reply', 'mention' => 'user', 'reaction' => 'smile', 'conversation' => 'mail', 'new_thread' => 'message',
        'solution' => 'check', 'warning' => 'alert', 'upgrade' => 'star', 'ad_published' => 'megaphone', 'ad_rejected' => 'megaphone',
        'follow' => 'users', 'profile_post' => 'message', 'badge' => 'star', 'ad_expiring' => 'megaphone', 'friend_request' => 'users', 'friend_accept' => 'users', 'inbox_full' => 'mail',
    ];

    /** Alerts that are also sent by e-mail, and the member preference that switches them off. */
    public const MAIL_PREFERENCE = [
        'reply' => 'email_on_reply', 'new_thread' => 'email_on_reply',
        'mention' => 'email_on_message', 'conversation' => 'email_on_message', 'ad_expiring' => 'email_on_message',
    ];

    public static function text(array $data): string
    {
        $actor = $data['actor'] ?? __('Someone');
        $thread = $data['thread'] ?? '';
        $title = $data['title'] ?? '';

        return match ($data['kind'] ?? '') {
            'reply' => __(':actor replied to the thread ":thread".', ['actor' => $actor, 'thread' => $thread]),
            'mention' => __(':actor mentioned you in the thread ":thread".', ['actor' => $actor, 'thread' => $thread]),
            'reaction' => __(':actor reacted :reaction to your post in ":thread".', ['actor' => $actor, 'reaction' => $data['reaction'] ?? '', 'thread' => $thread]),
            'conversation' => __(':actor sent a message in the conversation ":title".', ['actor' => $actor, 'title' => $title]),
            'new_thread' => __(':actor started the thread ":thread" in :forum.', ['actor' => $actor, 'thread' => $thread, 'forum' => $data['forum'] ?? '']),
            'solution' => __('Your reply in ":thread" was marked as the solution.', ['thread' => $thread]),
            'warning' => __('You have received a warning: :title (+:points points).', ['title' => $title, 'points' => $data['points'] ?? 0]),
            'upgrade' => __('Your membership package ":title" is active.', ['title' => $title]),
            'ad_published' => __('Your ad ":title" has been published.', ['title' => $title]),
            'ad_rejected' => __('Your ad ":title" was not accepted.', ['title' => $title]),
            'follow' => __(':actor started following you.', ['actor' => $actor]),
            'friend_request' => __(':actor sent you a friend request.', ['actor' => $actor]),
            'friend_accept' => __(':actor accepted your friend request.', ['actor' => $actor]),
            'inbox_full' => __('Your inbox is full, so new conversations cannot reach you. Leave old conversations to make room.'),
            'profile_post' => __(':actor wrote on your profile.', ['actor' => $actor]),
            'badge' => __('You have earned the badge ":title".', ['title' => $title]),
            'ad_expiring' => __('Your ad ":title" ends on :date. You can renew it with one click.', ['title' => $title, 'date' => $data['date'] ?? '']),
            // Add-ons bring their own sentence.
            default => is_string($data['text'] ?? null) && $data['text'] !== '' ? $data['text'] : __('You have a new alert.'),
        };
    }

    public static function icon(array $data): string
    {
        return self::ICONS[$data['kind'] ?? ''] ?? 'bell';
    }
}
