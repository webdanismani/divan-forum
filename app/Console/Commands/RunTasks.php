<?php

namespace App\Console\Commands;

use App\Forum\Tasks;
use Illuminate\Console\Command;

class RunTasks extends Command
{
    protected $signature = 'forum:run-tasks {--force : Run every task, whether it is due or not}';

    protected $description = 'Run the forum background tasks that are due (for a cron entry)';

    public function handle(Tasks $tasks): int
    {
        $ran = $tasks->runDue((bool) $this->option('force'));
        $this->components->info($ran ? 'Ran: '.implode(', ', $ran) : 'Nothing was due.');

        return self::SUCCESS;
    }
}
