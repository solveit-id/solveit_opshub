<?php

namespace App\Infrastructure\Connectors\Sftp;

use App\Infrastructure\Connectors\ConnectorConfig;

interface SftpSessionFactory
{
    public function fake(): bool;

    public function open(ConnectorConfig $config): SftpSession;
}
