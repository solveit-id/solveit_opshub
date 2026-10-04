<?php

namespace App\Infrastructure\Backup;

use App\Models\BackupRun;
use App\Models\Connector;

interface BackupCapacity
{
    public function fake(): bool;

    /** Worker evidence only. Browser estimates and destinations never prove quota/independence. */
    public function inspect(BackupRun $run, Connector $connector): CapacitySnapshot;
}
