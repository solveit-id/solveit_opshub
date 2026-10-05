<?php

namespace App\Infrastructure\Connectors\Cpanel;

use App\Infrastructure\Backup\BackupWritePermit;
use App\Infrastructure\Connectors\ConnectorConfig;

interface CpanelBackupTransport
{
    public function request(ConnectorConfig $config, BackupWritePermit $permit): CpanelResponse;
}
