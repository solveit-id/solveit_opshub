<?php

namespace App\Infrastructure\Connectors;

final class ConnectorFailure extends \RuntimeException
{
    public function __construct(public readonly ConnectorReason $reason)
    {
        parent::__construct($reason->message());
    }
}
