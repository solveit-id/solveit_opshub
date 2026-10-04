<?php

namespace App\Infrastructure\Backup;

use App\Models\BackupRun;
use App\Models\Connector;

class UnavailableBackupCapacity implements BackupCapacity
{
    public function fake(): bool
    {
        return false;
    }

    public function inspect(BackupRun $run, Connector $connector): CapacitySnapshot
    {
        // Explicit production boundary until a configured, independently validated storage adapter exists.
        return new CapacitySnapshot(null, null, null, false);
    }
}
