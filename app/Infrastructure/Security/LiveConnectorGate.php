<?php

namespace App\Infrastructure\Security;

use LogicException;

class LiveConnectorGate
{
    public function assertEnabled(): void
    {
        if (! config('opshub.live_connectors_enabled')) {
            throw new LogicException('Live connectors are disabled by configuration.');
        }
    }
}
