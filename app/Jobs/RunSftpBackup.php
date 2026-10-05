<?php

namespace App\Jobs;

use App\Application\Backups\SftpBackupFlow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunSftpBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3700;

    public function __construct(public int $runId) {}

    public function handle(SftpBackupFlow $flow): void
    {
        $flow->execute($this->runId);
    }
}
