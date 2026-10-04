<?php

namespace App\Infrastructure\Connectors\Cpanel;

use App\Infrastructure\Connectors\ConnectorConfig;

interface CpanelTransport
{
    /** The enum deliberately provides no provider write operation. */
    public function read(ConnectorConfig $config, CpanelRead $operation): CpanelResponse;
}
