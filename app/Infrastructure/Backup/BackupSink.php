<?php

namespace App\Infrastructure\Backup;

use App\Models\BackupRun;

interface BackupSink
{
    public function fake(): bool;

    public function available(): bool;

    /** Producer streams to the consumer and returns its private coverage manifest. Receipt is not verification. */
    public function receive(BackupRun $run, \Closure $producer): TransferReceipt;
}
