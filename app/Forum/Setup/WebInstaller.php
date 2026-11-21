<?php

namespace App\Forum\Setup;

use App\Forum\Installer;
use App\Forum\License\Licenses;
use App\Forum\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** The steps behind the /install page. */
class WebInstaller
{
    public const EXTENSIONS = ['pdo', 'mbstring', 'openssl', 'fileinfo', 'gd', 'ctype', 'json', 'tokenizer', 'xml'];

    public function __construct(protected Installer $installer) {}

    /**
     * A board counts as installed once it has a member; from then on the installer is closed.
     *
     * The answer must never turn into "not installed" because the database is away for a moment: the installer
     * runs without a session and would hand the board to whoever posts the form first. So a finished installation
     * leaves a marker file, and a configured board whose database does not answer counts as installed.
     */
    public static function installed(): bool
    {
        if (! self::configured()) {
            return false;
        }
        if (is_file(self::markerPath())) {
            return true;
        }

        try {
            $installed = Schema::hasTable('users') && DB::table('users')->exists();
        } catch (Throwable) {
            return true;
        }

        // Installed by an older version or from the command line: remember it without asking the database again.
        if ($installed) {
            self::markInstalled();
        }

        return $installed;
    }

    /** Written once the board has its first member; survives updates (storage/ is never replaced). */
    public static function markerPath(): string
    {
        $env = config('forum.install.env_path');

        return $env ? dirname($env).DIRECTORY_SEPARATOR.'installed' : storage_path('installed');
    }

    public static function markInstalled(): void
    {
        // The shared test database comes and goes; a marker in the real storage folder would outlive it.
        if (app()->runningUnitTests() && ! config('forum.install.env_path')) {
            return;
        }

        @file_put_contents(self::markerPath(), date('c')."\n");
    }

    /** Cheap check used on every request: is there an environment file with an application key? */
    public static function configured(): bool
    {
        return filled(config('app.key')) && is_file(self::envPath());
    }

    public static function envPath(): string
    {
        return config('forum.install.env_path') ?: base_path('.env');
    }

    /** @return array<int, array{0: string, 1: bool, 2: string}> label, passed, detail */
    public function requirements(): array
    {
        $checks = [['PHP 8.3+', version_compare(PHP_VERSION, '8.3.0', '>='), PHP_VERSION]];

        foreach (self::EXTENSIONS as $extension) {
            $checks[] = [$extension, extension_loaded($extension), ''];
        }
        $checks[] = ['pdo_mysql / pdo_sqlite', extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'), implode(', ', array_filter([
            extension_loaded('pdo_mysql') ? 'pdo_mysql' : null, extension_loaded('pdo_sqlite') ? 'pdo_sqlite' : null,
        ]))];

        foreach (['storage', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $directory) {
            $checks[] = [$directory.'/', is_writable(base_path($directory)), __('must be writable')];
        }
        $checks[] = ['.env', is_file(self::envPath()) ? is_writable(self::envPath()) : is_writable(dirname(self::envPath())), __('must be writable')];

        return $checks;
    }

    public function requirementsPass(): bool
    {
        return ! in_array(false, array_column($this->requirements(), 1), true);
    }

    /**
     * @param  array<string, mixed>  $input  validated installer form
     * @return string|null error message, null when the board is installed
     */
    public function install(array $input): ?string
    {
        $key = 'base64:'.base64_encode(random_bytes(32));
        $sqlite = $input['db_driver'] === 'sqlite';
        $sqlitePath = config('forum.install.sqlite_path') ?: database_path('database.sqlite');

        // 1. Point this request at the database and make sure it answers.
        try {
            if ($sqlite) {
                if (! is_file($sqlitePath)) {
                    touch($sqlitePath);
                }
                config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $sqlitePath]);
            } else {
                config([
                    'database.default' => 'mysql',
                    'database.connections.mysql.host' => $input['db_host'],
                    'database.connections.mysql.port' => (int) $input['db_port'],
                    'database.connections.mysql.database' => $input['db_name'],
                    'database.connections.mysql.username' => $input['db_user'],
                    'database.connections.mysql.password' => (string) ($input['db_password'] ?? ''),
                ]);
            }
            DB::purge();
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            return __('The database could not be reached: :error', ['error' => $e->getMessage()]);
        }

        // 2. The licence code of the forum software. Nothing is written before the vendor's licence server has
        //    accepted it for this address; without a working code the installation does not go on.
        $licenses = app(Licenses::class);
        $claim = $licenses->checkForInstall((string) ($input['license_key'] ?? ''), (string) $input['url']);
        if (is_string($claim)) {
            return $claim;
        }

        // 3. Environment file.
        $template = is_file(self::envPath()) ? file_get_contents(self::envPath())
            : (is_file(base_path('.env.example')) ? file_get_contents(base_path('.env.example')) : '');

        $values = [
            'APP_NAME' => $input['board_name'],
            'APP_ENV' => 'production',
            'APP_KEY' => $key,
            'APP_DEBUG' => false,
            'APP_URL' => rtrim($input['url'], '/'),
            'APP_LOCALE' => $input['locale'],
            'DB_CONNECTION' => $sqlite ? 'sqlite' : 'mysql',
            'LOG_LEVEL' => 'warning',
        ];
        if (! $sqlite) {
            $values += [
                'DB_HOST' => $input['db_host'], 'DB_PORT' => (int) $input['db_port'], 'DB_DATABASE' => $input['db_name'],
                'DB_USERNAME' => $input['db_user'], 'DB_PASSWORD' => (string) ($input['db_password'] ?? ''),
            ];
        }

        if (@file_put_contents(self::envPath(), EnvFile::render($template, $values)) === false) {
            $licenses->dropInstall($claim);

            return __('The .env file could not be written. Check the folder permissions.');
        }
        config(['app.key' => $key, 'app.url' => $values['APP_URL'], 'app.locale' => $input['locale']]);
        // The licence code is stored encrypted with the key that was made a moment ago.
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        // 4. Tables, default data, the licence, first administrator.
        try {
            Artisan::call('migrate', ['--force' => true]);
            $this->installer->installDefaults();
            $licenses->keepInstall($claim);
            $this->installer->createAdmin($input['admin_username'], $input['admin_email'], $input['admin_password']);
            self::markInstalled();

            $settings = app(Settings::class);
            $settings->flush();
            $settings->set(['board.name' => $input['board_name'], 'board.locale' => $input['locale']]);
        } catch (Throwable $e) {
            report($e);
            // The code must work again on the next try.
            $licenses->dropInstall($claim);

            return __('The installation failed: :error', ['error' => $e->getMessage()]);
        }

        // 5. public/storage link for avatars and banners; not possible on every host.
        try {
            if (! file_exists(public_path('storage'))) {
                Artisan::call('storage:link');
            }
        } catch (Throwable) {
            // reported on the result page
        }

        return null;
    }
}
