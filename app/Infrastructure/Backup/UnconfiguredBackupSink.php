<?php

namespace App\Infrastructure\Backup;

use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Models\BackupRun;

class UnconfiguredBackupSink implements BackupSink
{
    public function fake(): bool
    {
        return false;
    }

    public function available(): bool
    {
        return false;
    }

    public function receive(BackupRun $run, \Closure $producer): TransferReceipt
    {
        throw new ConnectorFailure(ConnectorReason::NotConfigured);
    }
}
