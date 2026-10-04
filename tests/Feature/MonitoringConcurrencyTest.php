<?php

namespace Tests\Feature;

use App\Application\Monitoring\ObservationRecorder;
use App\Infrastructure\Monitoring\ProbeResult;
use App\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\Support\MonitoringFixture;
use Tests\TestCase;

class MonitoringConcurrencyTest extends TestCase
{
    use DatabaseMigrations, MonitoringFixture;

    public function test_two_mysql_processes_cannot_duplicate_a_slot_lease_or_active_episode(): void
    {
        [, , $monitor] = $this->graph();
        $slots = $this->race('reserve', $monitor);
        $this->assertSame($slots[0], $slots[1]);
        $this->assertDatabaseCount('job_runs', 1);
        $leases = $this->race('lease', $monitor);
        sort($leases);
        $this->assertSame(['acquired', 'busy'], $leases);
        // Both racing processes have exited; release their fixture-only lease before a new run.
        $monitor->refresh()->update(['lease_token' => null, 'leased_until' => null]);
        $at = CarbonImmutable::now('UTC')->startOfMinute()->subMinutes(3);
        $this->sample($monitor, $at, 'fail');
        $this->sample($monitor, $at->addMinute(), 'fail');
        $recorder = app(ObservationRecorder::class);
        $third = $at->addMinutes(2);
        $token = $recorder->begin($monitor, $third);
        $recorder->finish($monitor, $token, $third, $third, $third->addSecond(), new ProbeResult('fail', 'HTTP_STATUS', ['status_code' => 503], fake: true));
        $episodes = $this->race('incident', $monitor);
        $this->assertSame($episodes[0], $episodes[1]);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseCount('incident_observations', 3);
    }

    private function race(string $operation, Monitor $monitor): array
    {
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $ready = (string) (microtime(true) + 2);
        $processes = [];
        for ($index = 0; $index < 2; $index++) {
            $process = new Process([PHP_BINARY, base_path('scripts/monitoring-race-worker.php'), $operation, (string) $monitor->id, $ready], base_path(), $environment, timeout: 20);
            $process->start();
            $processes[] = $process;
        }

        return array_map(function (Process $process): string {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

            return trim($process->getOutput());
        }, $processes);
    }
}
