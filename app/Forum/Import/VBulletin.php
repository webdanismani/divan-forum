<?php

namespace App\Forum\Import;

/** vBulletin 3.x and 4.x (vBulletin 5 has a different database and is not covered). */
class VBulletin extends Source
{
    /** Bit in forum.options: the forum can hold threads. Without it, it is a category. */
    protected const CAN_CONTAIN_THREADS = 4;

    protected const BANNED_GROUP = 8;

    public static function label(): string
    {
        return 'vBulletin 3 / 4';
    }

    public static function defaultPrefix(): string
    {
        return '';
    }

    public function counts(): array
    {
        return [
            'users' => $this->db->table('user')->count(),
            'forums' => $this->db->table('forum')->count(),
            'threads' => $this->db->table('thread')->where('open', '!=', 10)->count(),
            'posts' => $this->db->table('post')->count(),
        ];
    }

    public function users(int $after, int $limit): array
    {
        return $this->db->table('user as u')
            ->leftJoin('usertextfield as t', 't.userid', '=', 'u.userid')
            ->where('u.userid', '>', $after)->orderBy('u.userid')->limit($limit)
            ->get(['u.userid', 'u.username', 'u.email', 'u.password', 'u.salt', 'u.joindate', 'u.lastactivity', 'u.usertitle', 'u.customtitle', 'u.homepage', 'u.usergroupid', 't.signature'])
            ->map(fn ($row) => [
                'id' => (int) $row->userid,
                'username' => $this->plain((string) $row->username),
                'email' => (string) $row->email,
                'password' => null,
                'legacy' => ['scheme' => 'vbulletin', 'hash' => (string) $row->password, 'salt' => (string) $row->salt],
                'created_at' => (int) $row->joindate,
                'last_seen_at' => (int) $row->lastactivity,
                'title' => $row->customtitle ? strip_tags((string) $row->usertitle) : '',
                'location' => '',
                'website' => (string) $row->homepage,
                'signature' => (string) $row->signature,
                'banned' => (int) $row->usergroupid === self::BANNED_GROUP,
            ])->all();
    }

    public function forums(): array
    {
        return $this->db->table('forum')->orderBy('displayorder')->get()
            ->map(fn ($row) => [
                'id' => (int) $row->forumid,
                'parent_id' => max(0, (int) $row->parentid),
                'type' => ((int) $row->options & self::CAN_CONTAIN_THREADS) ? 'forum' : 'category',
                'title' => $this->plain(strip_tags((string) $row->title)),
                'description' => $this->plain(strip_tags((string) $row->description)),
                'order' => (int) $row->displayorder,
            ])->all();
    }

    public function threads(int $after, int $limit): array
    {
        // open = 10 marks a redirect left behind by a moved thread.
        return $this->db->table('thread')->where('threadid', '>', $after)->where('open', '!=', 10)
            ->orderBy('threadid')->limit($limit)->get()
            ->map(fn ($row) => [
                'id' => (int) $row->threadid,
                'forum_id' => (int) $row->forumid,
                'user_id' => (int) $row->postuserid,
                'title' => $this->plain((string) $row->title),
                'created_at' => (int) $row->dateline,
                'views' => (int) $row->views,
                'sticky' => (bool) $row->sticky,
                'locked' => ! $row->open,
                'state' => $this->state($row->visible, ['0' => 'moderated', '2' => 'deleted']),
            ])->all();
    }

    public function posts(int $after, int $limit): array
    {
        return $this->db->table('post')->where('postid', '>', $after)->orderBy('postid')->limit($limit)->get()
            ->map(fn ($row) => [
                'id' => (int) $row->postid,
                'thread_id' => (int) $row->threadid,
                'user_id' => (int) $row->userid,
                'body' => $this->body((string) $row->pagetext),
                'created_at' => (int) $row->dateline,
                'ip' => (string) $row->ipaddress,
                'state' => $this->state($row->visible, ['0' => 'moderated', '2' => 'deleted']),
            ])->all();
    }
}
