<?php

namespace App\Console\Commands;

use App\Forum\Import\Importer;
use App\Models\ImportRun;
use Illuminate\Console\Command;

class ImportForum extends Command
{
    protected $signature = 'forum:import {run? : Number of the import (default: the one that is running)}';

    protected $description = 'Continue an import that was started in the admin panel until it has finished';

    public function handle(Importer $importer): int
    {
        $run = $this->argument('run') ? ImportRun::find($this->argument('run')) : ImportRun::where('status', 'running')->latest('id')->first();

        if (! $run) {
            $this->components->error('There is no import to continue. Start one in the admin panel first.');

            return self::FAILURE;
        }

        while ($run->status === 'running') {
            $run = $importer->advance($run, 500);
            $this->output->write("\r".str_pad($importer->percent($run).'%  '.$run->step, 40));
        }
        $this->newLine();

        if ($run->status === 'failed') {
            $this->components->error((string) $run->error);

            return self::FAILURE;
        }

        $this->components->info('Imported: '.collect($run->stats['done'] ?? [])->map(fn ($count, $key) => "$count $key")->implode(', '));

        return self::SUCCESS;
    }
}
