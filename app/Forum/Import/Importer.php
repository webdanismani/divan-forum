<?php

namespace App\Forum\Import;

use App\Forum\BbCode\Renderer;
use App\Forum\NodeTree;
use App\Forum\Posting;
use App\Models\ImportRun;
use App\Models\Node;
use App\Models\Thread;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Moves members, forums, threads and posts from another forum package into
 * this one. The work is cut into small batches (one per call to advance())
 * so that it fits into ordinary web requests; where it stopped is stored
 * with the run, and a run can be continued at any time.
 */
class Importer
{
    public const SOURCES = ['xenforo' => XenForo::class, 'vbulletin' => VBulletin::class, 'mybb' => MyBb::class, 'smf' => Smf::class];

    public const STEPS = ['users', 'forums', 'threads', 'posts', 'rebuild', 'members', 'done'];

    public const CHARSETS = ['utf8mb4', 'utf8', 'latin1', 'latin5'];

    public function __construct(protected Renderer $renderer, protected Posting $posting) {}

    /** Opens the other forum's database. Throws when it cannot be reached. */
    public function connect(array $config): Connection
    {
        $prefix = (string) ($config['prefix'] ?? '');
        if (! preg_match('/^[A-Za-z0-9_]{0,30}$/', $prefix)) {
            throw new InvalidArgumentException('Invalid table prefix.');
        }

        config(['database.connections.import' => ($config['driver'] ?? 'mysql') === 'sqlite'
            ? ['driver' => 'sqlite', 'database' => $config['database'], 'prefix' => $prefix, 'foreign_key_constraints' => false]
            : [
                'driver' => 'mysql',
                'host' => $config['host'] ?? '127.0.0.1',
                'port' => (int) ($config['port'] ?? 3306),
                'database' => $config['database'],
                'username' => $config['username'] ?? '',
                'password' => $config['password'] ?? '',
                'charset' => in_array($config['charset'] ?? '', self::CHARSETS, true) ? $config['charset'] : 'utf8mb4',
                'collation' => null,
                'prefix' => $prefix,
                'strict' => false,
                'options' => [PDO::ATTR_TIMEOUT => 8],
            ]]);

        DB::purge('import');
        $connection = DB::connection('import');
        $connection->getPdo();

        return $connection;
    }

    public function source(string $key, array $config): Source
    {
        $class = self::SOURCES[$key] ?? throw new InvalidArgumentException('Unknown source.');

        return new $class($this->connect($config));
    }

    /** Checks that the database really holds that package, then creates the run. */
    public function start(string $key, array $config): ImportRun
    {
        $totals = $this->source($key, $config)->counts();

        return ImportRun::create([
            'source' => $key,
            'config' => Crypt::encryptString(json_encode($config)),
            'step' => 'users',
            'cursor' => 0,
            'stats' => ['totals' => $totals, 'done' => ['users' => 0, 'forums' => 0, 'threads' => 0, 'posts' => 0], 'merged' => 0, 'skipped' => 0],
            'status' => 'running',
        ]);
    }

    /** One batch of work. Returns the run with its new position. */
    public function advance(ImportRun $run, int $batch = 250): ImportRun
    {
        if ($run->status !== 'running') {
            return $run;
        }

        try {
            if (in_array($run->step, ['rebuild', 'members'], true)) {
                $run->step === 'rebuild' ? $this->rebuildThreads($run, $batch) : $this->rebuildMembers($run, $batch * 2);
            } else {
                $source = $this->source($run->source, json_decode(Crypt::decryptString($run->config), true));
                DB::transaction(fn () => match ($run->step) {
                    'users' => $this->users($run, $source, $batch),
                    'forums' => $this->forums($run, $source),
                    'threads' => $this->threads($run, $source, $batch * 2),
                    'posts' => $this->posts($run, $source, $batch),
                });
            }
        } catch (Throwable $e) {
            report($e);
            $run->update(['status' => 'failed', 'error' => self::text($e->getMessage(), 250)]);
        }

        return $run;
    }

    /** 0-100 over the whole run, for the progress bar. */
    public function percent(ImportRun $run): int
    {
        if ($run->status === 'done') {
            return 100;
        }

        $totals = $run->stats['totals'] ?? [];
        $done = $run->stats['done'] ?? [];
        $all = max(1, array_sum($totals));
        $percent = (int) floor(array_sum(array_map(fn ($key) => min($done[$key] ?? 0, $totals[$key] ?? 0), array_keys($totals))) / $all * 95);

        return min(99, $percent + (in_array($run->step, ['rebuild', 'members'], true) ? 2 : 0));
    }

    // ---- Steps -------------------------------------------------------------------------------

    protected function users(ImportRun $run, Source $source, int $limit): void
    {
        $rows = $source->users((int) $run->cursor, $limit);
        if ($rows === []) {
            $this->next($run, 'forums');

            return;
        }

        $group = UserGroup::system('registered')->id;
        $stats = $run->stats;
        $map = [];

        foreach ($rows as $row) {
            $email = mb_strtolower(trim(self::text($row['email'], 255)));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = 'member-'.$run->id.'-'.$row['id'].'@import.invalid';
            }

            // Somebody with this address is already a member here: their posts are attached to that account.
            $existing = DB::table('users')->where('email', $email)->value('id');
            if ($existing) {
                $map[] = ['run_id' => $run->id, 'type' => 'user', 'old_id' => $row['id'], 'new_id' => $existing];
                $stats['merged']++;

                continue;
            }

            $created = $this->date($row['created_at']);
            $signature = self::text($row['signature'], 500);

            $id = DB::table('users')->insertGetId([
                'username' => $this->freeUsername(self::text($row['username'], 30), $row['id']),
                'email' => $email,
                'email_verified_at' => str_ends_with($email, '@import.invalid') ? null : $created,
                // Without a usable hash nobody can sign in until the password is reset (or the old hash below matches).
                'password' => $row['password'] ?: Hash::make(Str::random(40)),
                'legacy_password' => $row['legacy'] ? json_encode($row['legacy']) : null,
                'user_group_id' => $group,
                'custom_title' => self::text($row['title'], 60) ?: null,
                'location' => self::text($row['location'], 80) ?: null,
                'website' => preg_match('#^https?://#i', (string) $row['website']) ? self::text($row['website'], 255) : null,
                'signature' => $signature ?: null,
                'signature_html' => $signature ? $this->renderer->render($signature) : null,
                'is_banned' => $row['banned'],
                'ban_reason' => $row['banned'] ? __('Banned on the previous forum.') : null,
                'last_seen_at' => $row['last_seen_at'] ? $this->date($row['last_seen_at']) : null,
                'created_at' => $created,
                'updated_at' => $created,
            ]);
            $map[] = ['run_id' => $run->id, 'type' => 'user', 'old_id' => $row['id'], 'new_id' => $id];
            $stats['done']['users']++;
        }

        $this->remember($map);
        $run->update(['cursor' => end($rows)['id'], 'stats' => $stats]);
    }

    protected function forums(ImportRun $run, Source $source): void
    {
        $rows = collect($source->forums())->keyBy('id');
        $order = (int) DB::table('nodes')->max('display_order');
        $stats = $run->stats;
        $new = [];

        // Parents first; whatever is left over after that hangs from a missing parent and goes to the top level.
        $pending = $rows->all();
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $id => $row) {
                $parent = $row['parent_id'] ?: null;
                if ($parent !== null && ! isset($new[$parent]) && isset($rows[$parent])) {
                    continue;
                }

                $title = self::text($row['title'], 255) ?: __('Forum').' '.$id;
                $new[$id] = DB::table('nodes')->insertGetId([
                    'parent_id' => $parent !== null ? ($new[$parent] ?? null) : null,
                    'type' => $row['type'],
                    'title' => $title,
                    'slug' => Node::uniqueSlug($title),
                    'description' => self::text($row['description'], 1000) ?: null,
                    'display_order' => $order + 1 + $row['order'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                unset($pending[$id]);
                $progress = true;
                $stats['done']['forums']++;
            }

            if (! $progress) {
                foreach ($pending as $id => $row) {
                    $pending[$id]['parent_id'] = 0; // a loop in the old data
                }
            }
        }

        $this->remember(collect($new)->map(fn ($newId, $oldId) => ['run_id' => $run->id, 'type' => 'forum', 'old_id' => $oldId, 'new_id' => $newId])->values()->all());
        app(NodeTree::class)->flush();
        $run->stats = $stats;
        $this->next($run, 'threads');
    }

    protected function threads(ImportRun $run, Source $source, int $limit): void
    {
        $rows = $source->threads((int) $run->cursor, $limit);
        if ($rows === []) {
            $this->next($run, 'posts');

            return;
        }

        $forums = $this->lookup($run, 'forum', array_column($rows, 'forum_id'));
        $users = $this->lookup($run, 'user', array_column($rows, 'user_id'));
        $categories = DB::table('nodes')->whereIn('id', array_values($forums))->where('type', '!=', 'forum')->pluck('id')->flip();
        $stats = $run->stats;
        $map = [];

        foreach ($rows as $row) {
            $node = $forums[$row['forum_id']] ?? null;
            // Threads of a forum that no longer exists (or that sit directly in a category) have nowhere to go.
            if (! $node || isset($categories[$node])) {
                $stats['skipped']++;

                continue;
            }

            $created = $this->date($row['created_at']);
            $map[] = ['run_id' => $run->id, 'type' => 'thread', 'old_id' => $row['id'], 'new_id' => DB::table('threads')->insertGetId([
                'node_id' => $node,
                'user_id' => $users[$row['user_id']] ?? null,
                'title' => self::text($row['title'], 150) ?: __('(no title)'),
                'state' => $row['state'],
                'is_sticky' => $row['sticky'],
                'is_locked' => $row['locked'],
                'view_count' => max(0, $row['views']),
                'last_post_at' => $created,
                'created_at' => $created,
                'updated_at' => $created,
            ])];
            $stats['done']['threads']++;
        }

        $this->remember($map);
        $run->update(['cursor' => end($rows)['id'], 'stats' => $stats]);
    }

    protected function posts(ImportRun $run, Source $source, int $limit): void
    {
        $rows = $source->posts((int) $run->cursor, $limit);
        if ($rows === []) {
            $this->next($run, 'rebuild');

            return;
        }

        $threads = $this->lookup($run, 'thread', array_column($rows, 'thread_id'));
        $users = $this->lookup($run, 'user', array_column($rows, 'user_id'));
        $stats = $run->stats;

        foreach ($rows as $row) {
            $thread = $threads[$row['thread_id']] ?? null;
            if (! $thread) {
                $stats['skipped']++;

                continue;
            }

            $created = $this->date($row['created_at']);
            $body = self::text($row['body']);
            $body = $body !== '' ? $body : '…';

            DB::table('posts')->insert([
                'thread_id' => $thread,
                'user_id' => $users[$row['user_id']] ?? null,
                'body' => $body,
                'body_html' => $this->renderer->render($body),
                'state' => $row['state'],
                'ip_address' => $row['ip'] && filter_var($row['ip'], FILTER_VALIDATE_IP) ? $row['ip'] : null,
                'created_at' => $created,
                'updated_at' => $created,
            ]);
            $stats['done']['posts']++;
        }

        $run->update(['cursor' => end($rows)['id'], 'stats' => $stats]);
    }

    /** First and last post, reply counts. Threads that arrived without a single post are removed again. */
    protected function rebuildThreads(ImportRun $run, int $limit): void
    {
        $ids = DB::table('import_map')->where('run_id', $run->id)->where('type', 'thread')->where('new_id', '>', $run->cursor)
            ->orderBy('new_id')->limit($limit)->pluck('new_id');

        if ($ids->isEmpty()) {
            $this->next($run, 'members');

            return;
        }

        foreach (Thread::whereIn('id', $ids)->get() as $thread) {
            if (! $thread->posts()->exists()) {
                $thread->delete();

                continue;
            }
            $this->posting->rebuildThread($thread);
        }

        $run->update(['cursor' => $ids->last()]);
    }

    protected function rebuildMembers(ImportRun $run, int $limit): void
    {
        $ids = DB::table('import_map')->where('run_id', $run->id)->where('type', 'user')->where('new_id', '>', $run->cursor)
            ->orderBy('new_id')->limit($limit)->pluck('new_id')->unique();

        if ($ids->isNotEmpty()) {
            User::whereIn('id', $ids)->get()->each(fn (User $user) => $this->posting->rebuildUser($user));
            $run->update(['cursor' => $ids->last()]);

            return;
        }

        $nodes = DB::table('import_map')->where('run_id', $run->id)->where('type', 'forum')->pluck('new_id');
        Node::whereIn('id', $nodes)->get()->each(fn (Node $node) => $this->posting->rebuildNode($node));

        // The connection details are not needed any more, and they include a password.
        $run->update(['step' => 'done', 'status' => 'done', 'cursor' => 0, 'config' => '']);
    }

    // ---- Helpers -----------------------------------------------------------------------------

    protected function next(ImportRun $run, string $step): void
    {
        $run->step = $step;
        $run->cursor = 0;
        $run->save();
    }

    protected function remember(array $map): void
    {
        foreach (array_chunk($map, 200) as $chunk) {
            DB::table('import_map')->insert($chunk);
        }
    }

    /** @return array<int, int> old id => new id */
    protected function lookup(ImportRun $run, string $type, array $oldIds): array
    {
        $oldIds = array_values(array_unique(array_filter($oldIds)));

        return $oldIds === [] ? [] : DB::table('import_map')->where('run_id', $run->id)->where('type', $type)->whereIn('old_id', $oldIds)->pluck('new_id', 'old_id')->all();
    }

    protected function freeUsername(string $wanted, int $oldId): string
    {
        $wanted = trim(preg_replace('/\s+/u', ' ', $wanted));
        $name = $wanted !== '' ? $wanted : 'member'.$oldId;
        $i = 2;

        while (DB::table('users')->where('username', $name)->exists()) {
            $suffix = '-'.$i++;
            $name = mb_substr($wanted !== '' ? $wanted : 'member'.$oldId, 0, 30 - strlen($suffix)).$suffix;
        }

        return $name;
    }

    protected function date(int $timestamp): Carbon
    {
        // In the forum's own time zone: the query builder stores the wall-clock time it is given.
        return $timestamp > 0 ? Carbon::createFromTimestamp($timestamp)->setTimezone(config('app.timezone')) : now();
    }

    /** Valid UTF-8, trimmed, cut to the column's length. Old Turkish boards often hold Windows-1254 bytes in latin1 columns. */
    public static function text(?string $value, ?int $limit = null): string
    {
        $value = (string) $value;
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1254');
        }
        $value = trim(str_replace("\0", '', $value));

        return $limit ? mb_substr($value, 0, $limit) : $value;
    }
}
