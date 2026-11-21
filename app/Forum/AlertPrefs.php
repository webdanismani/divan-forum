<?php

namespace App\Forum;

use App\Models\User;

/**
 * Which alerts a member wants, and how: on the site, by e-mail, as a browser notification.
 * Alerts are grouped so that the settings page stays short; a kind that is in no group
 * (add-ons bring their own) is always shown on the site.
 */
class AlertPrefs
{
    public const CHANNELS = ['site' => 'On the site', 'mail' => 'E-mail', 'push' => 'Browser'];

    /** Group => [label, the alert kinds in it]. */
    public const GROUPS = [
        'replies' => ['Replies in threads I watch and new threads in forums I watch', ['reply', 'new_thread']],
        'mentions' => ['Somebody mentions me', ['mention']],
        'conversations' => ['Private messages', ['conversation']],
        'reactions' => ['Reactions, solutions and badges', ['reaction', 'solution', 'badge']],
        'social' => ['Followers, friend requests and messages on my profile', ['follow', 'friend_request', 'friend_accept', 'profile_post']],
        'account' => ['About my account: warnings, packages, ads, a full inbox', ['warning', 'upgrade', 'ad_published', 'ad_rejected', 'ad_expiring', 'inbox_full', 'new_device', 'support']],
    ];

    /** Alerts about the account itself are always shown on the site; only their e-mail and browser side can be switched off. */
    public const ALWAYS_ON_SITE = ['account'];

    public function group(string $kind): ?string
    {
        foreach (self::GROUPS as $key => [, $kinds]) {
            if (in_array($kind, $kinds, true)) {
                return $key;
            }
        }

        return null;
    }

    public function wants(User $user, string $kind, string $channel): bool
    {
        $group = $this->group($kind);
        if ($group === null) {
            return $channel !== 'mail';
        }

        return $this->all($user)[$group][$channel];
    }

    /** @return array<string, array{site: bool, mail: bool, push: bool}> every group with the member's choice or the default */
    public function all(User $user): array
    {
        $stored = json_decode((string) $user->alert_prefs, true);
        $stored = is_array($stored) ? $stored : [];
        $out = [];

        foreach (array_keys(self::GROUPS) as $group) {
            $default = [
                'site' => true,
                // Before these choices existed there were two e-mail switches; they stay the starting point.
                'mail' => match ($group) {
                    'replies' => (bool) $user->email_on_reply,
                    'mentions', 'conversations', 'account' => (bool) $user->email_on_message,
                    default => false,
                },
                'push' => true,
            ];
            foreach ($default as $channel => $value) {
                $out[$group][$channel] = array_key_exists($channel, $stored[$group] ?? []) ? (bool) $stored[$group][$channel] : $value;
            }
            if (in_array($group, self::ALWAYS_ON_SITE, true)) {
                $out[$group]['site'] = true;
            }
            // A browser notification points at an alert on the site; without the alert there is nothing to show.
            $out[$group]['push'] = $out[$group]['push'] && $out[$group]['site'];
        }

        return $out;
    }

    /** @param array<string, array<string, mixed>> $input the ticked boxes of the settings form */
    public function save(User $user, array $input): void
    {
        $prefs = [];
        foreach (array_keys(self::GROUPS) as $group) {
            foreach (array_keys(self::CHANNELS) as $channel) {
                $prefs[$group][$channel] = ! empty($input[$group][$channel]);
            }
        }

        $user->forceFill([
            'alert_prefs' => json_encode($prefs),
            // The two older switches follow, for everything that still reads them.
            'email_on_reply' => $prefs['replies']['mail'],
            'email_on_message' => $prefs['mentions']['mail'] || $prefs['conversations']['mail'],
        ])->save();
    }
}
