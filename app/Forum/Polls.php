<?php

namespace App\Forum;

use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Polls
{
    public const MAX_OPTIONS = 12;

    /** Split the "one option per line" field of the thread form. @return string[] */
    public static function parseOptions(?string $text): array
    {
        $lines = array_map(fn ($line) => mb_substr(trim($line), 0, 150), preg_split('/\r\n|\r|\n/', (string) $text));

        return array_slice(array_values(array_unique(array_filter($lines, fn ($line) => $line !== ''))), 0, self::MAX_OPTIONS);
    }

    /** @param string[] $options */
    public function create(Thread $thread, string $question, array $options, int $maxChoices = 1, ?int $closeDays = null, bool $canChange = true): Poll
    {
        $poll = Poll::create([
            'thread_id' => $thread->id,
            'question' => $question,
            'max_choices' => max(1, min($maxChoices, count($options))),
            'can_change' => $canChange,
            'closes_at' => $closeDays ? now()->addDays($closeDays) : null,
        ]);

        foreach (array_values($options) as $i => $text) {
            PollOption::create(['poll_id' => $poll->id, 'text' => $text, 'display_order' => $i]);
        }

        return $poll;
    }

    /** @param int[] $optionIds */
    public function vote(Poll $poll, User $user, array $optionIds): void
    {
        $valid = $poll->options()->whereIn('id', $optionIds)->pluck('id')->all();

        if ($poll->isClosed()) {
            throw ValidationException::withMessages(['poll' => __('This poll is closed.')]);
        }
        if ($valid === [] || count($valid) > $poll->max_choices) {
            throw ValidationException::withMessages(['poll' => trans_choice('Choose one option.|Choose up to :count options.', $poll->max_choices, ['count' => $poll->max_choices])]);
        }

        $voted = DB::table('poll_votes')->where('poll_id', $poll->id)->where('user_id', $user->id)->exists();
        if ($voted && ! $poll->can_change) {
            throw ValidationException::withMessages(['poll' => __('You have already voted in this poll.')]);
        }

        DB::transaction(function () use ($poll, $user, $valid) {
            DB::table('poll_votes')->where('poll_id', $poll->id)->where('user_id', $user->id)->delete();
            foreach ($valid as $optionId) {
                DB::table('poll_votes')->insert(['poll_id' => $poll->id, 'poll_option_id' => $optionId, 'user_id' => $user->id, 'created_at' => now()]);
            }
        });

        $this->recount($poll);
    }

    /** @return int[] option ids the member chose */
    public function choices(Poll $poll, ?User $user): array
    {
        return $user ? DB::table('poll_votes')->where('poll_id', $poll->id)->where('user_id', $user->id)->pluck('poll_option_id')->all() : [];
    }

    public function recount(Poll $poll): void
    {
        $counts = DB::table('poll_votes')->where('poll_id', $poll->id)->selectRaw('poll_option_id, count(*) as total')->groupBy('poll_option_id')->pluck('total', 'poll_option_id');

        foreach ($poll->options()->get() as $option) {
            $option->update(['vote_count' => (int) ($counts[$option->id] ?? 0)]);
        }
        $poll->update(['voter_count' => DB::table('poll_votes')->where('poll_id', $poll->id)->distinct()->count('user_id')]);
    }
}
