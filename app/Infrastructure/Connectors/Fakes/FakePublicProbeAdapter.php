<?php

namespace App\Infrastructure\Connectors\Fakes;

class FakePublicProbeAdapter
{
    public function observe(string $scenario = 'pass'): FixtureResult
    {
        return match ($scenario) {
            'timeout' => new FixtureResult('unknown', 'http_observe', 'NETWORK_TIMEOUT', 'Fake probe timeout.'),
            'fail' => new FixtureResult('fail', 'http_observe', 'HTTP_STATUS_UNEXPECTED', 'Fake probe failure.'),
            default => new FixtureResult('pass', 'http_observe', null, 'Fake probe pass.'),
        };
    }
}
