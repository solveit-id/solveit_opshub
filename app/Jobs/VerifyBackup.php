<?php

namespace App\Jobs;

use App\Application\Backups\BackupVerification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class VerifyBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3700;

    public function __construct(public int $runId, public bool $manual = false) {}

    public function handle(BackupVerification $verification): void
    {
        $verification->execute($this->runId, $this->manual);
    }
}
