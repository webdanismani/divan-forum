<?php

namespace App\Forum;

use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Collection;

/** Threads with a similar title: shown under a thread and while somebody types the title of a new one. */
class Similar
{
    /** Words that say nothing about the subject. */
    protected const STOP = ['nasıl', 'neden', 'nedir', 'hakkında', 'için', 'ile', 'veya', 'ama', 'çok', 'daha', 'gibi', 'kadar', 'olan', 'olarak', 'yardım', 'lütfen', 'acil', 'sorun', 'konu', 'this', 'that', 'with', 'from', 'what', 'have', 'help', 'about', 'please'];

    public function __construct(protected NodeTree $tree) {}

    /** @return Collection<int, Thread> */
    public function threads(string $title, ?User $viewer, ?int $exceptId = null, int $limit = 5): Collection
    {
        $words = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title)))
            ->filter(fn ($word) => mb_strlen($word) >= 4 && ! in_array($word, self::STOP, true))
            ->unique()->take(6)->values();

        if ($words->isEmpty()) {
            return collect();
        }

        $candidates = Thread::with('node')
            ->whereIn('node_id', $this->tree->viewableIds($viewer))
            ->where('state', 'visible')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhere('title', 'like', '%'.addcslashes($word, '%_\\').'%');
                }
            })
            ->orderByDesc('last_post_at')->limit(150)->get();

        // Most shared words first, then the most recently active.
        return $candidates
            ->map(function (Thread $thread) use ($words) {
                $lower = mb_strtolower($thread->title);
                $thread->similarity = $words->filter(fn ($word) => str_contains($lower, $word))->count();

                return $thread;
            })
            ->filter(fn (Thread $thread) => $thread->similarity >= min(2, $words->count()))
            ->sortByDesc(fn (Thread $thread) => [$thread->similarity, $thread->last_post_at?->getTimestamp() ?? 0])
            ->take($limit)->values();
    }
}
