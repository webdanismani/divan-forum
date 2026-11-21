<?php

namespace App\Forum;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Backups made from the admin panel, for hosts without a shell: the database as a compressed SQL file
 * and the uploaded files as a zip. They are kept outside the web root and handed out to administrators only.
 */
class Backups
{
    /** Tables whose rows are not worth keeping: structure only. */
    protected const NO_ROWS = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    protected const NAME = '/^(db|files)-\d{8}-\d{6}-[a-z0-9]{10}\.(sql\.gz|sqlite\.gz|zip)$/';

    public function directory(): string
    {
        $directory = storage_path('app/backups');
        if (! is_dir($directory)) {
            File::makeDirectory($directory, 0750, true);
            File::put($directory.'/.htaccess', "Require all denied\n");
        }

        return $directory;
    }

    /** @return array<int, array{name: string, kind: string, size: int, time: int}> newest first */
    public function all(): array
    {
        $found = [];
        foreach (File::files($this->directory()) as $file) {
            if (preg_match(self::NAME, $file->getFilename(), $match)) {
                $found[] = ['name' => $file->getFilename(), 'kind' => $match[1], 'size' => $file->getSize(), 'time' => $file->getMTime()];
            }
        }
        usort($found, fn ($a, $b) => $b['time'] <=> $a['time']);

        return $found;
    }

    public function path(string $name): ?string
    {
        $path = $this->directory().'/'.$name;

        return preg_match(self::NAME, $name) && is_file($path) ? $path : null;
    }

    public function delete(string $name): bool
    {
        $path = $this->path($name);

        return $path !== null && File::delete($path);
    }

    /** Keeps the newest $keep backups of a kind and removes the rest. */
    public function prune(string $kind, int $keep): int
    {
        $removed = 0;
        foreach (array_slice(array_values(array_filter($this->all(), fn ($backup) => $backup['kind'] === $kind)), max(1, $keep)) as $old) {
            $removed += (int) $this->delete($old['name']);
        }

        return $removed;
    }

    /** The scheduled job: a database backup every N days, when the administrator asked for it. */
    public function auto(): ?string
    {
        $days = (int) setting('backup.auto_days');
        if ($days < 1) {
            return null;
        }
        $latest = collect($this->all())->firstWhere('kind', 'db');
        if ($latest && $latest['time'] > now()->subDays($days)->getTimestamp()) {
            return null;
        }
        $name = $this->database();
        $this->prune('db', (int) setting('backup.keep'));

        return $name;
    }

    /** Writes the whole database into one compressed file and returns its name. */
    public function database(): string
    {
        @set_time_limit(600);
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $stamp = now()->format('Ymd-His').'-'.strtolower(Str::random(10));

        // A file database is copied as it is.
        if ($driver === 'sqlite' && is_file($connection->getDatabaseName())) {
            $name = "db-$stamp.sqlite.gz";
            $source = fopen($connection->getDatabaseName(), 'rb');
            $target = gzopen($this->directory().'/'.$name, 'wb6');
            if (! $source || ! $target) {
                throw new RuntimeException('The database file could not be read or the backup could not be written.');
            }
            while (! feof($source)) {
                gzwrite($target, (string) fread($source, 1 << 20));
            }
            fclose($source);
            gzclose($target);

            return $name;
        }
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException("Backups are not available for the database driver \"$driver\".");
        }

        $name = "db-$stamp.sql.gz";
        $out = gzopen($this->directory().'/'.$name, 'wb6');
        if (! $out) {
            throw new RuntimeException('The backup file could not be written.');
        }
        gzwrite($out, '-- '.config('app.name').' — '.now()->toDateTimeString()."\n");

        if ($driver === 'sqlite') {
            foreach ($connection->select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
                $quoted = '"'.str_replace('"', '""', $table->name).'"';
                gzwrite($out, "DROP TABLE IF EXISTS $quoted;\n".$table->sql.";\n\n");
                if (! in_array($table->name, self::NO_ROWS, true)) {
                    $described = $connection->select("PRAGMA table_info($quoted)");
                    $this->writeRows($out, $quoted, array_map(fn ($column) => $column->name, $described), '"',
                        collect($described)->contains(fn ($column) => $column->name === 'id' && str_contains(strtolower((string) $column->type), 'int')));
                }
            }
            gzclose($out);

            return $name;
        }

        gzwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        foreach ($connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
            $table = (string) array_values((array) $row)[0];
            $quoted = '`'.str_replace('`', '``', $table).'`';
            $create = (array) $connection->selectOne("SHOW CREATE TABLE $quoted");
            gzwrite($out, "DROP TABLE IF EXISTS $quoted;\n".array_values($create)[1].";\n\n");
            if (! in_array($table, self::NO_ROWS, true)) {
                $described = $connection->select("SHOW COLUMNS FROM $quoted");
                $this->writeRows($out, $quoted, array_map(fn ($column) => $column->Field, $described), '`',
                    collect($described)->contains(fn ($column) => $column->Field === 'id' && str_contains(strtolower((string) $column->Type), 'int')));
            }
        }
        gzwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($out);

        return $name;
    }

    /**
     * The rows of one table as INSERT statements, 500 read at a time. Tables with a numeric id are read
     * in id order; the others (text ids, pivots) page by page.
     *
     * @param  resource  $out
     * @param  array<int, string>  $columns
     */
    protected function writeRows($out, string $quoted, array $columns, string $mark, bool $byId): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $list = implode(', ', array_map(fn ($column) => $mark.str_replace($mark, $mark.$mark, $column).$mark, $columns));
        $id = $mark.'id'.$mark;
        $last = 0;
        $offset = 0;
        do {
            $rows = $byId
                ? $connection->select("SELECT * FROM $quoted WHERE $id > ? ORDER BY $id LIMIT 500", [$last])
                : $connection->select("SELECT * FROM $quoted LIMIT 500 OFFSET $offset");
            if (! $rows) {
                break;
            }
            $values = [];
            foreach ($rows as $record) {
                $values[] = '('.implode(', ', array_map(fn ($value) => match (true) {
                    $value === null => 'NULL',
                    is_int($value), is_float($value) => (string) $value,
                    is_bool($value) => $value ? '1' : '0',
                    default => $pdo->quote((string) $value),
                }, (array) $record)).')';
            }
            foreach (array_chunk($values, 100) as $chunk) {
                gzwrite($out, "INSERT INTO $quoted ($list) VALUES\n".implode(",\n", $chunk).";\n");
            }
            if ($byId) {
                $last = (int) end($rows)->id;
            } else {
                $offset += count($rows);
            }
        } while (count($rows) === 500);
        gzwrite($out, "\n");
    }

    /** Zips everything members and the administrator uploaded (avatars, attachments, logos, icons). */
    public function files(): string
    {
        @set_time_limit(900);
        $root = storage_path('app/public');
        $name = 'files-'.now()->format('Ymd-His').'-'.strtolower(Str::random(10)).'.zip';
        $zip = new ZipArchive;
        if ($zip->open($this->directory().'/'.$name, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The backup file could not be written.');
        }
        $zip->addEmptyDir('public');
        if (is_dir($root)) {
            foreach (File::allFiles($root, true) as $file) {
                if ($file->getFilename() === '.gitignore') {
                    continue;
                }
                $zip->addFile($file->getPathname(), 'public/'.str_replace('\\', '/', $file->getRelativePathname()));
                $zip->setCompressionName('public/'.str_replace('\\', '/', $file->getRelativePathname()), ZipArchive::CM_STORE); // pictures do not shrink
            }
        }
        $zip->close();

        return $name;
    }
}
