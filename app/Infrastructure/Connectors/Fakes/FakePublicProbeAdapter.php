<?php

namespace App\Infrastructure\Connectors\Fakes;

use App\Infrastructure\Connectors\ConnectorResult;

class FakePublicProbeAdapter
{
    public function observe(string $scenario = 'pass'): ConnectorResult
    {
        return match ($scenario) {
            'timeout' => new ConnectorResult('unknown', 'http_observe', 'NETWORK_TIMEOUT', 'Fake probe timeout.'),
            'fail' => new ConnectorResult('fail', 'http_observe', 'HTTP_STATUS_UNEXPECTED', 'Fake probe failure.'),
            default => new ConnectorResult('pass', 'http_observe', null, 'Fake probe pass.'),
        };
    }
}
