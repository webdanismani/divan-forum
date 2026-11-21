<?php

namespace App\Forum\Moderation;

use App\Forum\Posting;
use App\Forum\Security\Bans;
use App\Models\Ban;
use App\Models\Post;
use App\Models\ProfilePost;
use App\Models\Report;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** One click against a spammer: ban the account and take everything it wrote off the board. */
class SpamCleaner
{
    public function __construct(protected Posting $posting, protected Bans $bans) {}

    /** @return array{threads: int, posts: int, messages: int} what was removed */
    public function clean(User $spammer, User $actor, bool $banAddresses = false, ?string $actorIp = null): array
    {
        $threads = Thread::where('user_id', $spammer->id)->where('state', '!=', 'deleted')->limit(500)->get();
        foreach ($threads as $thread) {
            $this->posting->setThreadState($thread, 'deleted', $actor);
        }

        // Replies in other people's threads.
        $posts = Post::where('posts.user_id', $spammer->id)->where('posts.state', '!=', 'deleted')
            ->whereHas('thread', fn ($q) => $q->where('user_id', '!=', $spammer->id)->orWhereNull('user_id'))
            ->limit(1000)->get();
        foreach ($posts as $post) {
            $this->posting->setPostState($post, 'deleted', $actor);
        }

        $messages = ProfilePost::where('user_id', $spammer->id)->delete();
        DB::table('node_watches')->where('user_id', $spammer->id)->delete();
        Report::where('status', 'open')->whereIn('post_id', Post::where('user_id', $spammer->id)->select('id'))
            ->update(['status' => 'resolved', 'handled_by' => $actor->id, 'handled_at' => now()]);

        $spammer->forceFill(['is_banned' => true, 'ban_reason' => 'Spam', 'banned_until' => null, 'signature' => null, 'signature_html' => null, 'website' => null])->save();

        if ($banAddresses) {
            foreach (array_unique(array_filter([$spammer->last_ip, $spammer->registration_ip])) as $ip) {
                // Never the moderator's own address, and not twice.
                if ($ip !== $actorIp && ! Ban::where('type', 'ip')->where('value', $ip)->exists()) {
                    Ban::create(['type' => 'ip', 'value' => $ip, 'reason' => 'Spam: '.$spammer->username, 'expires_at' => now()->addDays(30), 'created_by' => $actor->id]);
                }
            }
            $this->bans->flush();
        }

        $this->posting->log($actor, 'user.spamClean', 'user', $spammer->id, $spammer->username.' ('.$threads->count().' / '.$posts->count().')');

        return ['threads' => $threads->count(), 'posts' => $posts->count(), 'messages' => (int) $messages];
    }
}
