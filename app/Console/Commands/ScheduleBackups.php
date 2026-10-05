<?php

namespace App\Console\Commands;

use App\Application\Backups\BackupIssues;
use App\Application\Backups\BackupScheduler;
use Illuminate\Console\Command;

class ScheduleBackups extends Command
{
    protected $signature = 'opshub:backups:schedule';

    protected $description = 'Queue approved canonical backup slots and retention reports; no provider I/O.';

    public function handle(BackupScheduler $scheduler): int
    {
        $this->info('Queued backup slots: '.$scheduler->tick().'; retention reports: '.$scheduler->retention());
        $this->info('Internal backup incidents/tasks: '.app(BackupIssues::class)->tick());

        return self::SUCCESS;
    }
}
