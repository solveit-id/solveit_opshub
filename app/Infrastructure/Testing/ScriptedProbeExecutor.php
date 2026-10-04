<?php

namespace App\Infrastructure\Testing;

use App\Application\Monitoring\ProbeExecutor;
use App\Infrastructure\Monitoring\ProbeResult;
use App\Models\Monitor;
use LogicException;

class ScriptedProbeExecutor extends ProbeExecutor
{
    public function __construct(private array $outcomes) {}

    public function execute(Monitor $monitor): ProbeResult
    {
        if (! app()->environment('testing') || ! str_ends_with(config('database.connections.mysql.database'), '_test')) {
            throw new LogicException('Scripted probes are restricted to an isolated testing database.');
        }
        $outcome = array_shift($this->outcomes);
        if (! in_array($outcome, ['pass', 'fail', 'unknown'], true)) {
            throw new LogicException('Scripted probe sequence exhausted or invalid.');
        }

        return new ProbeResult($outcome, $outcome === 'pass' ? 'HTTP_OK' : ($outcome === 'fail' ? 'HTTP_STATUS' : 'PROBE_EXECUTION_UNAVAILABLE'), ['status_code' => $outcome === 'pass' ? 200 : 503, 'content_check' => 'not_configured'], source: 'fake_probe', fake: true);
    }
}
