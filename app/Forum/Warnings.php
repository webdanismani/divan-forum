<?php

namespace App\Forum;

use App\Models\Post;
use App\Models\User;
use App\Models\Warning;
use App\Models\WarningAction;
use App\Notifications\ForumAlert;

/** Warning points: issuing, expiring and the automatic bans that follow. */
class Warnings
{
    public function __construct(protected Posting $posting) {}

    public function issue(User $member, User $actor, string $title, int $points, int $expiryDays, ?string $note = null, ?Post $post = null): Warning
    {
        $warning = Warning::create([
            'user_id' => $member->id,
            'issued_by' => $actor->id,
            'post_id' => $post?->id,
            'title' => $title,
            'points' => $points,
            'note' => $note,
            'expires_at' => $expiryDays > 0 ? now()->addDays($expiryDays) : null,
        ]);

        $this->recalculate($member);
        $this->applyActions($member);
        $this->posting->log($actor, 'user.warn', 'user', $member->id, $member->username.': '.$title.' (+'.$points.')');

        $member->notify(new ForumAlert('warning', null, route('members.show', $member), ['title' => $title, 'points' => $points]));

        return $warning;
    }

    public function remove(Warning $warning, User $actor): void
    {
        $member = $warning->user;
        $warning->delete();

        $this->recalculate($member);
        $this->posting->log($actor, 'user.unwarn', 'user', $member->id, $member->username.': '.$warning->title);
    }

    public function recalculate(User $member): int
    {
        $points = (int) Warning::where('user_id', $member->id)->active()->sum('points');
        $member->forceFill(['warning_points' => $points])->save();

        return $points;
    }

    /** Scheduled: warnings run out without anybody touching them. */
    public function expire(): void
    {
        User::where('warning_points', '>', 0)->limit(1000)->get()->each(fn (User $member) => $this->recalculate($member));
    }

    protected function applyActions(User $member): void
    {
        $action = WarningAction::where('points', '<=', $member->warning_points)->orderByDesc('points')->first();

        if (! $action || $member->isAdmin()) {
            return;
        }

        $until = $action->ban_days > 0 ? now()->addDays($action->ban_days) : null;
        // Never shorten a ban that is already longer.
        if ($member->isBanned() && ($member->banned_until === null || ($until && $member->banned_until->gt($until)))) {
            return;
        }

        $member->forceFill([
            'is_banned' => true,
            'ban_reason' => __('Warning point limit reached (:points points)', ['points' => $member->warning_points]),
            'banned_until' => $until,
        ])->save();
    }
}
