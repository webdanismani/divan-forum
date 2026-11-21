<?php

namespace App\Forum\Import;

/** Simple Machines Forum 2.0 / 2.1 */
class Smf extends Source
{
    /** Categories and boards have separate numbering in SMF; categories are moved out of the boards' range. */
    protected const CATEGORY_OFFSET = 1000000000;

    public static function label(): string
    {
        return 'SMF 2.0 / 2.1';
    }

    public static function defaultPrefix(): string
    {
        return 'smf_';
    }

    public function counts(): array
    {
        return [
            'users' => $this->db->table('members')->count(),
            'forums' => $this->db->table('categories')->count() + $this->db->table('boards')->count(),
            'threads' => $this->db->table('topics')->count(),
            'posts' => $this->db->table('messages')->count(),
        ];
    }

    public function users(int $after, int $limit): array
    {
        return $this->db->table('members')->where('id_member', '>', $after)->orderBy('id_member')->limit($limit)->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id_member,
                'username' => $this->plain((string) $row->member_name),
                'email' => (string) $row->email_address,
                'password' => null,
                'legacy' => ['scheme' => 'smf', 'hash' => (string) $row->passwd, 'name' => (string) $row->member_name],
                'created_at' => (int) $row->date_registered,
                'last_seen_at' => (int) $row->last_login,
                'title' => $this->plain((string) ($row->usertitle ?? '')),
                'location' => $this->plain((string) ($row->location ?? '')),
                'website' => (string) ($row->website_url ?? ''),
                'signature' => Markup::convert((string) ($row->signature ?? ''), true),
                // 11 and above: the account is banned (10 was added to its activation state).
                'banned' => (int) $row->is_activated >= 10,
            ])->all();
    }

    public function forums(): array
    {
        $categories = $this->db->table('categories')->orderBy('cat_order')->get()->map(fn ($row) => [
            'id' => self::CATEGORY_OFFSET + (int) $row->id_cat,
            'parent_id' => 0,
            'type' => 'category',
            'title' => $this->plain((string) $row->name),
            'description' => '',
            'order' => (int) $row->cat_order,
        ]);

        $boards = $this->db->table('boards')->orderBy('board_order')->get()->map(fn ($row) => [
            'id' => (int) $row->id_board,
            'parent_id' => (int) $row->id_parent ?: self::CATEGORY_OFFSET + (int) $row->id_cat,
            'type' => 'forum',
            'title' => $this->plain((string) $row->name),
            'description' => $this->plain(strip_tags((string) $row->description)),
            'order' => (int) $row->board_order,
        ]);

        return $categories->concat($boards)->all();
    }

    public function threads(int $after, int $limit): array
    {
        // A topic has no title of its own: it is the subject of its first message.
        return $this->db->table('topics as t')->leftJoin('messages as m', 'm.id_msg', '=', 't.id_first_msg')
            ->where('t.id_topic', '>', $after)->orderBy('t.id_topic')->limit($limit)
            ->get(['t.id_topic', 't.id_board', 't.id_member_started', 't.num_views', 't.is_sticky', 't.locked', 't.approved', 'm.subject', 'm.poster_time'])
            ->map(fn ($row) => [
                'id' => (int) $row->id_topic,
                'forum_id' => (int) $row->id_board,
                'user_id' => (int) $row->id_member_started,
                'title' => $this->plain((string) $row->subject),
                'created_at' => (int) $row->poster_time,
                'views' => (int) $row->num_views,
                'sticky' => (bool) $row->is_sticky,
                'locked' => (bool) $row->locked,
                'state' => $row->approved ? 'visible' : 'moderated',
            ])->all();
    }

    public function posts(int $after, int $limit): array
    {
        return $this->db->table('messages')->where('id_msg', '>', $after)->orderBy('id_msg')->limit($limit)->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id_msg,
                'thread_id' => (int) $row->id_topic,
                'user_id' => (int) $row->id_member,
                'body' => Markup::convert((string) $row->body, true),
                'created_at' => (int) $row->poster_time,
                'ip' => is_string($row->poster_ip) && filter_var($row->poster_ip, FILTER_VALIDATE_IP) ? $row->poster_ip : null,
                'state' => $row->approved ? 'visible' : 'moderated',
            ])->all();
    }
}
