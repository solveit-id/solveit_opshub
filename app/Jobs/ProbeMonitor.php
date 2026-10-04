<?php

namespace App\Jobs;

use App\Application\Monitoring\IncidentEngine;
use App\Application\Monitoring\MonitoringScheduler;
use App\Application\Monitoring\ObservationRecorder;
use App\Application\Monitoring\ProbeExecutor;
use App\Infrastructure\Monitoring\ProbeResult;
use App\Models\JobRun;
use App\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProbeMonitor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 40;

    public int $tries = 2;

    public function __construct(public int $runId) {}

    public function handle(ObservationRecorder $recorder, IncidentEngine $incidents, ProbeExecutor $executor): void
    {
        $started = CarbonImmutable::now('UTC');
        $claimed = DB::transaction(function () use ($recorder, $started): ?array {
            $reference = JobRun::findOrFail($this->runId);
            Monitor::whereKey($reference->resource_id)->lockForUpdate()->firstOrFail();
            $run = JobRun::whereKey($this->runId)->lockForUpdate()->firstOrFail();
            if (! in_array($run->state, ['queued', 'running'], true) || ($run->state === 'running' && $run->leased_until?->greaterThan($started))) {
                return null;
            }
            $monitor = Monitor::findOrFail($run->resource_id);
            if (! $monitor->enabled || ! $monitor->activeProjects()->where('lifecycle', 'active')->exists()) {
                $run->update(['state' => 'cancelled']);

                return null;
            }
            $token = $recorder->begin($monitor, $started);
            if ($token === null) {
                $run->update(['state' => 'coalesced', 'last_error_code' => 'MONITOR_BUSY']);

                return null;
            }
            $run->update(['state' => 'running', 'lease_owner' => $token, 'leased_until' => $started->addSeconds(45), 'attempts' => $run->attempts + 1]);

            return [$run, $monitor, $token];
        });
        if ($claimed === null) {
            return;
        }
        [$run, $monitor, $token] = $claimed;
        app(MonitoringScheduler::class)->heartbeat($run->organization_id, 'worker:probe', $started);
        try {
            $result = $executor->execute($monitor);
        } catch (Throwable) {
            // Infrastructure failure is unknown, never fabricated target downtime or a raw exception payload.
            $result = new ProbeResult('unknown', 'PROBE_EXECUTION_UNAVAILABLE');
        }
        $completed = CarbonImmutable::now('UTC');
        DB::transaction(function () use ($run, $monitor, $token, $recorder, $incidents, $started, $completed, $result): void {
            $locked = JobRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->lease_owner !== $token) {
                return;
            }
            $observation = $recorder->finish($monitor, $token, CarbonImmutable::instance($run->scheduled_slot), $started, $completed, $result, $incidents->inMaintenance($monitor, $completed));
            $incidents->evaluate($observation);
            $locked->update(['state' => 'completed', 'leased_until' => null, 'last_error_code' => $result->outcome === 'unknown' ? $result->reason : null]);
        });
        app(MonitoringScheduler::class)->heartbeat($run->organization_id, 'worker:probe', $completed);
    }
}
