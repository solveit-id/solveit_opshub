<?php

namespace App\Infrastructure\Backup;

use App\Models\BackupRun;
use App\Models\Connector;

interface CpanelBackupSource
{
    public function fake(): bool;

    public function configured(): bool;

    public function request(BackupRun $run, Connector $connector, BackupWritePermit $permit): SourceAcceptance;

    public function observe(BackupRun $run, Connector $connector, ?string $providerReference): SourceObservation;

    public function stream(BackupRun $run, Connector $connector, SourceArtifact $artifact, \Closure $consume): array;
}
