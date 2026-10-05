<?php

namespace App\Infrastructure\Backup;

use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Models\BackupRun;

class UnconfiguredObjectStore implements PrivateObjectStore
{
    public function fake(): bool
    {
        return false;
    }

    public function protection(BackupRun $run): array
    {
        return ['configured' => false, 'private' => false, 'independent' => false, 'transport_protected' => false];
    }

    public function put(BackupRun $run, string $reference, string $version, \Closure $producer): array
    {
        throw new ConnectorFailure(ConnectorReason::NotConfigured);
    }

    public function metadata(string $reference, string $version): array
    {
        throw new ConnectorFailure(ConnectorReason::NotConfigured);
    }

    public function read(string $reference, string $version)
    {
        throw new ConnectorFailure(ConnectorReason::NotConfigured);
    }

    public function delete(string $reference, string $version, BackupDeletionPermit $permit): void
    {
        throw new ConnectorFailure(ConnectorReason::NotConfigured);
    }

    public function presence(string $reference, string $version): string
    {
        return 'unknown';
    }
}
