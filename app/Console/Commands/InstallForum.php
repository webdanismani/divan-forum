<?php

namespace App\Console\Commands;

use App\Forum\Installer;
use App\Forum\License\Licenses;
use App\Forum\Setup\WebInstaller;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

class InstallForum extends Command
{
    protected $signature = 'forum:install
        {--license= : Licence code of the forum software (free, from the vendor\'s shop)}
        {--username= : Administrator username}
        {--email= : Administrator e-mail address}
        {--password= : Administrator password}';

    protected $description = 'Run the migrations, create the default data and the first administrator';

    public function handle(Installer $installer, Licenses $licenses): int
    {
        // A new installation asks for the licence code of the forum before anything is written, exactly as the
        // web installer does. A forum that is installed already is only brought up to date.
        $claim = null;
        if ($this->isNew($licenses)) {
            $key = $this->option('license') ?: $this->ask('Licence code of the forum (free: '.config('forum.license.get_url').')');
            $claim = $licenses->checkForInstall((string) $key, (string) config('app.url'));
            if (is_string($claim)) {
                $this->components->error($claim);
                $this->line('  The code is tied to the address in APP_URL ('.Licenses::host((string) config('app.url')).'). Nothing has been installed.');

                return self::FAILURE;
            }
        }

        try {
            $this->call('migrate', ['--force' => true]);
            $installer->installDefaults();
            if ($claim !== null) {
                $licenses->keepInstall($claim);
            }
        } catch (Throwable $e) {
            if ($claim !== null) {
                $licenses->dropInstall($claim); // the code must work again on the next try
            }

            throw $e;
        }
        $this->components->info('Default groups, reactions and forums are in place.');

        if (! file_exists(public_path('storage'))) {
            $this->call('storage:link');
        }

        if (User::whereHas('group', fn ($q) => $q->where('system_key', 'admin'))->exists()) {
            $this->components->info('An administrator already exists - skipping account creation.');

            return self::SUCCESS;
        }

        $data = [
            'username' => $this->option('username') ?: $this->ask('Administrator username', 'admin'),
            'email' => $this->option('email') ?: $this->ask('Administrator e-mail'),
            'password' => $this->option('password') ?: $this->secret('Administrator password'),
        ];

        $validator = Validator::make($data, [
            'username' => ['required', 'min:3', 'max:30', 'regex:/^[\p{L}\p{N}_-]+$/u', 'unique:users,username'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $installer->createAdmin($data['username'], $data['email'], $data['password']);
        WebInstaller::markInstalled();
        $this->components->info("Administrator \"{$data['username']}\" created. The forum is ready.");

        return self::SUCCESS;
    }

    /** No administrator and no licence code of the forum yet: this run installs the forum. */
    protected function isNew(Licenses $licenses): bool
    {
        try {
            if (Schema::hasTable('users') && User::whereHas('group', fn ($q) => $q->where('system_key', 'admin'))->exists()) {
                return false;
            }

            // The code was accepted on an earlier run that stopped at the administrator's details.
            return ! Schema::hasTable('licenses') || $licenses->get($licenses->coreProduct()) === null;
        } catch (Throwable) {
            return true;
        }
    }
}
