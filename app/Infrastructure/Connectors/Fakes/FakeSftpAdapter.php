<?php

namespace App\Infrastructure\Connectors\Fakes;

use App\Infrastructure\Connectors\ConnectorResult;

class FakeSftpAdapter
{
    public function validate(string $scenario = 'supported'): ConnectorResult
    {
        return match ($scenario) {
            'host_key_mismatch' => new ConnectorResult('fail', 'sftp_read', 'HOST_KEY_MISMATCH', 'Fake SFTP host key mismatch.'),
            default => new ConnectorResult('supported', 'sftp_read', null, 'Fake SFTP read capability available.'),
        };
    }
}
