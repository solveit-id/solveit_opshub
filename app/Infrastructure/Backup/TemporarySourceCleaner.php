<?php

namespace App\Infrastructure\Backup;

use App\Models\BackupArtifact;
use App\Models\BackupRun;

interface TemporarySourceCleaner
{
    public function fake(): bool;

    /** Provider adapter must prove immutable archive/run/account ownership, never infer from a filename. */
    public function owned(BackupRun $run, BackupArtifact $artifact): bool;

    /** Recheck ownership atomically at deletion; never delete operator/provider-owned archives. */
    public function deleteOwned(BackupRun $run, BackupArtifact $artifact): void;
}
