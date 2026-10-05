<?php

namespace App\Infrastructure\Backup;

use App\Infrastructure\Connectors\ConnectorReason;

final readonly class SourceAcceptance
{
    public function __construct(public string $state, public ?string $providerReference, public ?ConnectorReason $reason, public bool $fake = false)
    {
        if (! in_array($state, ['accepted', 'rejected', 'uncertain'], true) || ($providerReference !== null && ! preg_match('/^[1-9][0-9]{0,9}$/D', $providerReference))
            || ($state === 'accepted' && ($providerReference === null || $reason !== null))
            || ($state !== 'accepted' && $reason === null)) {
            throw new \InvalidArgumentException('Invalid source acceptance.');
        }
    }
}
