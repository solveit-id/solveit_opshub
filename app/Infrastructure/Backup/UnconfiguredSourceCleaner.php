<?php

namespace App\Infrastructure\Backup;

use App\Models\BackupArtifact;
use App\Models\BackupRun;

class UnconfiguredSourceCleaner implements TemporarySourceCleaner
{
    public function fake(): bool
    {
        return false;
    }

    public function owned(BackupRun $run, BackupArtifact $artifact): bool
    {
        return false;
    }

    public function deleteOwned(BackupRun $run, BackupArtifact $artifact): void
    {
        throw new \LogicException('Native source ownership/delete protocol is not configured.');
    }
}
