<?php

namespace App\Jobs;

use App\Application\Connectors\ConnectorTests;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TestConnector implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $runId) {}

    public function handle(ConnectorTests $tests): void
    {
        $tests->execute($this->runId);
    }
}
