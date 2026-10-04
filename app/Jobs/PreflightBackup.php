<?php

namespace App\Jobs;

use App\Application\Backups\BackupRuns;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PreflightBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $runId) {}

    public function handle(BackupRuns $runs): void
    {
        $runs->preflight($this->runId);
    }
}
