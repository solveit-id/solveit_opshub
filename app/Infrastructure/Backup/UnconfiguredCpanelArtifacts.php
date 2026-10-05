<?php

namespace App\Infrastructure\Backup;

use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Models\BackupRun;
use App\Models\Connector;

class UnconfiguredCpanelArtifacts implements CpanelSourceArtifacts
{
    public function configured(): bool
    {
        return false;
    }

    public function observe(BackupRun $run, Connector $connector, ?string $providerReference): SourceObservation
    {
        // list_backups returns dates, not completion/PID/path proof. Filename matching is insufficient.
        return new SourceObservation('unknown', reason: ConnectorReason::NotConfigured);
    }

    public function stream(BackupRun $run, Connector $connector, SourceArtifact $artifact, \Closure $consume): array
    {
        throw new ConnectorFailure(ConnectorReason::NotConfigured);
    }
}
