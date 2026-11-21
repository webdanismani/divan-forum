<?php

namespace App\Forum\Import;

/** MyBB 1.8 */
class MyBb extends Source
{
    protected const BANNED_GROUP = 7;

    public static function label(): string
    {
        return 'MyBB 1.8';
    }

    public static function defaultPrefix(): string
    {
        return 'mybb_';
    }

    public function counts(): array
    {
        return [
            'users' => $this->db->table('users')->count(),
            'forums' => $this->db->table('forums')->count(),
            'threads' => $this->db->table('threads')->where('closed', 'not like', 'moved|%')->count(),
            'posts' => $this->db->table('posts')->count(),
        ];
    }

    public function users(int $after, int $limit): array
    {
        return $this->db->table('users')->where('uid', '>', $after)->orderBy('uid')->limit($limit)->get()
            ->map(fn ($row) => [
                'id' => (int) $row->uid,
                'username' => (string) $row->username,
                'email' => (string) $row->email,
                'password' => null,
                'legacy' => ['scheme' => 'mybb', 'hash' => (string) $row->password, 'salt' => (string) $row->salt],
                'created_at' => (int) $row->regdate,
                'last_seen_at' => (int) $row->lastactive,
                'title' => (string) $row->usertitle,
                'location' => '',
                'website' => (string) $row->website,
                'signature' => (string) $row->signature,
                'banned' => (int) $row->usergroup === self::BANNED_GROUP,
            ])->all();
    }

    public function forums(): array
    {
        return $this->db->table('forums')->orderBy('disporder')->get()
            ->map(fn ($row) => [
                'id' => (int) $row->fid,
                'parent_id' => (int) $row->pid,
                'type' => $row->type === 'c' ? 'category' : 'forum',
                'title' => $this->plain((string) $row->name),
                'description' => $this->plain(strip_tags((string) $row->description)),
                'order' => (int) $row->disporder,
            ])->all();
    }

    public function threads(int $after, int $limit): array
    {
        // closed = "moved|<id>" is a redirect to a thread that lives elsewhere.
        return $this->db->table('threads')->where('tid', '>', $after)->where('closed', 'not like', 'moved|%')
            ->orderBy('tid')->limit($limit)->get()
            ->map(fn ($row) => [
                'id' => (int) $row->tid,
                'forum_id' => (int) $row->fid,
                'user_id' => (int) $row->uid,
                'title' => (string) $row->subject,
                'created_at' => (int) $row->dateline,
                'views' => (int) $row->views,
                'sticky' => (bool) $row->sticky,
                'locked' => (string) $row->closed === '1',
                'state' => $this->state($row->visible, ['0' => 'moderated', '-1' => 'deleted', '-2' => 'deleted']),
            ])->all();
    }

    public function posts(int $after, int $limit): array
    {
        return $this->db->table('posts')->where('pid', '>', $after)->orderBy('pid')->limit($limit)->get()
            ->map(fn ($row) => [
                'id' => (int) $row->pid,
                'thread_id' => (int) $row->tid,
                'user_id' => (int) $row->uid,
                'body' => $this->body((string) $row->message),
                'created_at' => (int) $row->dateline,
                // Stored in binary form (4 or 16 bytes).
                'ip' => in_array(strlen((string) $row->ipaddress), [4, 16], true) ? (@inet_ntop((string) $row->ipaddress) ?: null) : null,
                'state' => $this->state($row->visible, ['0' => 'moderated', '-1' => 'deleted', '-2' => 'deleted']),
            ])->all();
    }
}
