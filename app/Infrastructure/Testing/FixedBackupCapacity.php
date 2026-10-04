<?php

namespace App\Infrastructure\Testing;

use App\Infrastructure\Backup\BackupCapacity;
use App\Infrastructure\Backup\CapacitySnapshot;
use App\Models\BackupRun;
use App\Models\Connector;

class FixedBackupCapacity implements BackupCapacity
{
    public function fake(): bool
    {
        return true;
    }

    public int $calls = 0;

    public ?\Closure $duringInspect = null;

    public function __construct(public CapacitySnapshot $result)
    {
        if (! app()->environment('testing') || ! $result->fake) {
            throw new \LogicException('Capacity fixture is test-only.');
        }
    }

    public function inspect(BackupRun $run, Connector $connector): CapacitySnapshot
    {
        $this->calls++;
        if ($this->duringInspect) {
            ($this->duringInspect)($run, $connector);
        }

        return $this->result;
    }
}
