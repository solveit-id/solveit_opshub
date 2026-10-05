<?php

namespace App\Jobs;

use App\Application\Backups\BackupRetention;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ApplyBackupRetention implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $reportId) {}

    public function handle(BackupRetention $retention): void
    {
        $retention->apply($this->reportId);
    }
}
