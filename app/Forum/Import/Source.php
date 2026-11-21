<?php

namespace App\Forum\Import;

use Illuminate\Database\ConnectionInterface;

/**
 * Reads another forum package's database and hands its content over in one
 * common shape. Every list method returns rows with an id greater than
 * $after, lowest first, so an import can stop and continue anywhere.
 *
 * user:   id, username, email, password (bcrypt hash or null), legacy (array|null), created_at, last_seen_at,
 *         title, location, website, signature, banned
 * forum:  id, parent_id, type (category|forum), title, description, order
 * thread: id, forum_id, user_id, title, created_at, views, sticky, locked, state
 * post:   id, thread_id, user_id, body, created_at, ip, state
 */
abstract class Source
{
    public function __construct(protected ConnectionInterface $db) {}

    abstract public static function label(): string;

    /** Table prefix the package installs with. */
    abstract public static function defaultPrefix(): string;

    /** @return array{users: int, forums: int, threads: int, posts: int} */
    abstract public function counts(): array;

    abstract public function users(int $after, int $limit): array;

    /** All forums and categories at once: there are never many. */
    abstract public function forums(): array;

    abstract public function threads(int $after, int $limit): array;

    abstract public function posts(int $after, int $limit): array;

    /** The package's message markup as this forum's BBCode. */
    public function body(string $text): string
    {
        return Markup::convert($text);
    }

    /** Text that the package stores with HTML entities (titles, names). */
    protected function plain(string $value): string
    {
        return html_entity_decode(Importer::text($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    protected function state(mixed $value, array $map): string
    {
        return $map[(string) $value] ?? 'visible';
    }
}
