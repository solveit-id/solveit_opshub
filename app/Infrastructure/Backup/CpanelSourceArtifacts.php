<?php

namespace App\Infrastructure\Backup;

use App\Models\BackupRun;
use App\Models\Connector;

interface CpanelSourceArtifacts
{
    /** Requires a documented, sandbox-proven PID/artifact association, completion and read path. */
    public function configured(): bool;

    public function observe(BackupRun $run, Connector $connector, ?string $providerReference): SourceObservation;

    public function stream(BackupRun $run, Connector $connector, SourceArtifact $artifact, \Closure $consume): array;
}
