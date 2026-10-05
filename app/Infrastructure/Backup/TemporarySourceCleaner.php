<?php

namespace App\Infrastructure\Backup;

use App\Models\BackupArtifact;
use App\Models\BackupRun;

interface TemporarySourceCleaner
{
    public function fake(): bool;

    /** Provider adapter must prove immutable archive/run/account ownership, never infer from a filename. */
    public function owned(BackupRun $run, BackupArtifact $artifact): bool;

    /** Recheck immutable ownership and consume permit immediately before deletion; no intervening I/O. */
    public function deleteOwned(BackupRun $run, BackupArtifact $artifact, BackupDeletionPermit $permit): void;
}
