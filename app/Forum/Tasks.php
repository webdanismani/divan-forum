<?php

namespace App\Forum;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Background jobs for hosts without a cron entry: ForumContext calls tick()
 * after a response has been sent, at most once every few minutes. With cron,
 * `php artisan forum:run-tasks` does the same.
 */
class Tasks
{
    /** @return array<string, array{0: int, 1: callable}> name => [minutes between runs, job] */
    public function definitions(): array
    {
        return [
            'mail.send' => [1, fn () => app(Mailer::class)->work()],
            'mail.digests' => [60, fn () => app(Mailer::class)->sendDigests()],
            'upgrades.expire' => [30, fn () => app(Upgrades::class)->expireDue()],
            'warnings.expire' => [60, fn () => app(Warnings::class)->expire()],
            'rewards.badges' => [30, fn () => app(Rewards::class)->awardBadges()],
            'rewards.promotions' => [60, fn () => app(Rewards::class)->promote()],
            'attachments.purge' => [720, fn () => app(Attachments::class)->purgeOrphans()],
            'ads.remind' => [180, fn () => app(Ads::class)->remindExpiring()],
            'market.updates' => [720, fn () => app(Store\Client::class)->checkUpdates()],
            'backups.auto' => [180, fn () => app(Backups::class)->auto()],
            // Licences are asked about every hour: a cancelled one stops its module or theme at the next answer.
            'license.refresh' => [60, fn () => [app(License\Licenses::class)->refresh(), app(Themes::class)->dropUnlicensed()]],
        ];
    }

    /** Cheap gate so that only one request in a while looks at the task list. */
    public function tick(): void
    {
        try {
            if (Cache::add('forum.tasks.tick', 1, 60)) {
                $this->runDue();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return string[] names of the tasks that ran */
    public function runDue(bool $force = false): array
    {
        $ran = [];

        foreach ($this->definitions() as $name => [$minutes, $job]) {
            $key = 'forum.tasks.last.'.$name;
            if (! $force && Cache::get($key, 0) > now()->subMinutes($minutes)->getTimestamp()) {
                continue;
            }

            Cache::forever($key, now()->getTimestamp());
            try {
                $job();
                $ran[] = $name;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $ran;
    }
}
