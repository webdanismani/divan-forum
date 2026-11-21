<?php

namespace App\Forum\Import;

/** XenForo 2.x */
class XenForo extends Source
{
    public static function label(): string
    {
        return 'XenForo 2';
    }

    public static function defaultPrefix(): string
    {
        return 'xf_';
    }

    public function counts(): array
    {
        return [
            'users' => $this->db->table('user')->count(),
            'forums' => $this->db->table('node')->whereIn('node_type_id', ['Category', 'Forum'])->count(),
            'threads' => $this->db->table('thread')->where('discussion_type', '!=', 'redirect')->count(),
            'posts' => $this->db->table('post')->count(),
        ];
    }

    public function users(int $after, int $limit): array
    {
        return $this->db->table('user as u')
            ->leftJoin('user_authenticate as a', 'a.user_id', '=', 'u.user_id')
            ->leftJoin('user_profile as p', 'p.user_id', '=', 'u.user_id')
            ->where('u.user_id', '>', $after)->orderBy('u.user_id')->limit($limit)
            ->get(['u.user_id', 'u.username', 'u.email', 'u.register_date', 'u.last_activity', 'u.custom_title', 'u.is_banned', 'a.data', 'p.location', 'p.website', 'p.signature'])
            ->map(function ($row) {
                // The stored value is a serialised array; only the bcrypt hash inside it is of interest.
                preg_match('/\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}/', (string) $row->data, $hash);

                return [
                    'id' => (int) $row->user_id,
                    'username' => (string) $row->username,
                    'email' => (string) $row->email,
                    'password' => $hash[0] ?? null,
                    'legacy' => null,
                    'created_at' => (int) $row->register_date,
                    'last_seen_at' => (int) $row->last_activity,
                    'title' => (string) $row->custom_title,
                    'location' => (string) $row->location,
                    'website' => (string) $row->website,
                    'signature' => (string) $row->signature,
                    'banned' => (bool) $row->is_banned,
                ];
            })->all();
    }

    public function forums(): array
    {
        return $this->db->table('node')->whereIn('node_type_id', ['Category', 'Forum'])->orderBy('lft')->get()
            ->map(fn ($row) => [
                'id' => (int) $row->node_id,
                'parent_id' => (int) $row->parent_node_id,
                'type' => $row->node_type_id === 'Category' ? 'category' : 'forum',
                'title' => (string) $row->title,
                'description' => (string) $row->description,
                'order' => (int) $row->display_order,
            ])->all();
    }

    public function threads(int $after, int $limit): array
    {
        return $this->db->table('thread')->where('thread_id', '>', $after)->where('discussion_type', '!=', 'redirect')
            ->orderBy('thread_id')->limit($limit)->get()
            ->map(fn ($row) => [
                'id' => (int) $row->thread_id,
                'forum_id' => (int) $row->node_id,
                'user_id' => (int) $row->user_id,
                'title' => (string) $row->title,
                'created_at' => (int) $row->post_date,
                'views' => (int) $row->view_count,
                'sticky' => (bool) $row->sticky,
                'locked' => ! $row->discussion_open,
                'state' => $this->state($row->discussion_state, ['moderated' => 'moderated', 'deleted' => 'deleted']),
            ])->all();
    }

    public function posts(int $after, int $limit): array
    {
        return $this->db->table('post')->where('post_id', '>', $after)->orderBy('post_id')->limit($limit)->get()
            ->map(fn ($row) => [
                'id' => (int) $row->post_id,
                'thread_id' => (int) $row->thread_id,
                'user_id' => (int) $row->user_id,
                'body' => $this->body((string) $row->message),
                'created_at' => (int) $row->post_date,
                'ip' => null,
                'state' => $this->state($row->message_state, ['moderated' => 'moderated', 'deleted' => 'deleted']),
            ])->all();
    }
}
