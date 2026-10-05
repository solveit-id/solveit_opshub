<?php

namespace App\Infrastructure\Backup;

use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;

class UnconfiguredBackupKeys implements BackupKeyResolver
{
    public function reference(int $organizationId, string $destination): ?string
    {
        return null;
    }

    public function resolve(string $reference): string
    {
        throw new ConnectorFailure(ConnectorReason::NotConfigured);
    }
}
