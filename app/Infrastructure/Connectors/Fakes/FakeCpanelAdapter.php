<?php

namespace App\Infrastructure\Connectors\Fakes;

use App\Infrastructure\Connectors\ConnectorResult;

class FakeCpanelAdapter
{
    public function discover(string $scenario = 'unsupported'): ConnectorResult
    {
        return match ($scenario) {
            'supported' => new ConnectorResult('supported', 'full_backup_trigger', null, 'Fake cPanel capability available.'),
            'denied' => new ConnectorResult('permission_denied', 'full_backup_trigger', 'PERMISSION_DENIED', 'Fake cPanel access denied.'),
            default => new ConnectorResult('unsupported', 'full_backup_trigger', 'UNSUPPORTED_CAPABILITY', 'Fake cPanel capability unavailable.'),
        };
    }
}
