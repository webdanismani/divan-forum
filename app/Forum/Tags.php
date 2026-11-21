<?php

namespace App\Forum;

use App\Models\Tag;
use App\Models\Thread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Tags
{
    public function limit(): int
    {
        return max(0, (int) setting('posting.max_tags'));
    }

    public function enabled(): bool
    {
        return $this->limit() > 0;
    }

    /** "php, Laravel 13 ,  php" -> ['php', 'laravel 13'] @return string[] */
    public function parse(?string $input): array
    {
        $names = [];
        foreach (explode(',', (string) $input) as $name) {
            $name = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} .+#-]+/u', '', $name)));
            $name = preg_replace('/\s+/u', ' ', $name);
            if (mb_strlen($name) >= 2 && mb_strlen($name) <= 30 && Str::slug($name) !== '') {
                $names[Str::slug($name)] = $name;
            }
        }

        return array_slice($names, 0, $this->limit(), true);
    }

    /** Replace the tags of a thread with the ones typed into the form. */
    public function sync(Thread $thread, ?string $input): void
    {
        if (! $this->enabled()) {
            return;
        }

        $ids = [];
        foreach ($this->parse($input) as $slug => $name) {
            $ids[] = Tag::firstOrCreate(['slug' => $slug], ['name' => $name])->id;
        }

        $changed = $thread->tags()->sync($ids);
        $this->recount(array_merge($changed['attached'], $changed['detached']));
    }

    /** @param int[] $tagIds */
    public function recount(array $tagIds): void
    {
        foreach (array_unique($tagIds) as $id) {
            $count = DB::table('tag_thread')->where('tag_id', $id)->count();
            $count > 0 ? Tag::whereKey($id)->update(['use_count' => $count]) : Tag::whereKey($id)->delete();
        }
    }
}
