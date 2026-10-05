<?php

namespace App\Infrastructure\Backup;

use App\Infrastructure\Connectors\ConnectorReason;

final readonly class SourceObservation
{
    public function __construct(public string $state, public ?SourceArtifact $artifact = null, public ?ConnectorReason $reason = null, public bool $fake = false)
    {
        if (! in_array($state, ['busy', 'ready', 'idle_proven', 'unknown', 'failed'], true) || ($state === 'ready' && ! $artifact)
            || ($state !== 'ready' && $artifact !== null)) {
            throw new \InvalidArgumentException('Invalid source observation.');
        }
    }
}
