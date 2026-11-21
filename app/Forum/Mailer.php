<?php

namespace App\Forum;

use App\Mail\AlertMail;
use App\Models\Thread;
use App\Models\User;
use App\Notifications\ForumAlert;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Notification e-mails. They are queued and sent by the background task
 * runner, so writing a post never waits for a mail server.
 */
class Mailer
{
    /** Listener: every stored alert may also go out as an e-mail. */
    public function alertStored(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notification instanceof ForumAlert || ! $event->notifiable instanceof User) {
            return;
        }

        $this->mailAlert($event->notifiable, $event->notification->toArray($event->notifiable));
    }

    /** Sends the alert as an e-mail when the member wants this kind by e-mail. */
    public function mailAlert(User $user, array $data): void
    {
        if (! Mailing::enabled() || ! app(AlertPrefs::class)->wants($user, (string) ($data['kind'] ?? ''), 'mail') || ! $user->isVerified() || $user->isBanned()) {
            return;
        }

        // The sentence carries titles and names that members chose: in the Markdown body it is plain text.
        $text = AlertText::text($data);
        $this->queue($user, new AlertMail($text, __('Hello :name,', ['name' => $user->username]), [AlertMail::plain($text)], __('Open'), $data['url']));
    }

    /** Scheduled: "what happened while you were away" for members who asked for it. */
    public function sendDigests(): int
    {
        if (! Mailing::enabled()) {
            return 0;
        }

        $sent = 0;
        $members = User::whereIn('email_digest', ['daily', 'weekly'])
            ->whereNotNull('email_verified_at')
            ->where('is_banned', false)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('email_digest', 'daily')->where(fn ($q) => $q->whereNull('digest_sent_at')->orWhere('digest_sent_at', '<', now()->subHours(23))))
                ->orWhere(fn ($q) => $q->where('email_digest', 'weekly')->where(fn ($q) => $q->whereNull('digest_sent_at')->orWhere('digest_sent_at', '<', now()->subDays(7)->addHour()))))
            ->limit(200)
            ->get();

        foreach ($members as $member) {
            $since = max($member->digest_sent_at ?? now()->subDays($member->email_digest === 'daily' ? 1 : 7), now()->subDays(14));
            $member->forceFill(['digest_sent_at' => now()])->saveQuietly();

            $threads = Thread::whereIn('node_id', app(NodeTree::class)->viewableIds($member))
                ->where('state', 'visible')
                ->where('last_post_at', '>', $since)
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $member->id))
                ->orderByDesc('reply_count')
                ->limit(12)
                ->get();

            if ($threads->isEmpty()) {
                continue;
            }

            $lines = $threads->map(fn (Thread $thread) => '- ['.AlertMail::plain($thread->title).']('.route('threads.show', $thread).') — '
                .trans_choice(':count reply|:count replies', $thread->reply_count, ['count' => $thread->reply_count]))->all();

            $this->queue($member, new AlertMail(
                __('What is new at :board', ['board' => setting('board.name')]),
                __('Hello :name,', ['name' => $member->username]),
                [__('These threads were active since your last summary:'), implode("\n", $lines)],
                __('Open the forum'),
                route('whats-new'),
            ));
            $sent++;
        }

        return $sent;
    }

    /** Scheduled: hand the queued messages to the mail server. */
    public function work(): void
    {
        if (config('queue.default') === 'sync' || ! DB::table('jobs')->exists()) {
            return;
        }

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 25, '--tries' => 3, '--quiet' => true]);
    }

    protected function queue(User $user, AlertMail $mail): void
    {
        try {
            Mail::to($user->email)->locale($user->locale ?: (string) setting('board.locale'))->queue($mail);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
