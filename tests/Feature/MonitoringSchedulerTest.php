<?php

namespace Tests\Feature;

use App\Application\Monitoring\IncidentEngine;
use App\Application\Monitoring\MonitoringHealth;
use App\Application\Monitoring\MonitoringScheduler;
use App\Application\Monitoring\ObservationRecorder;
use App\Application\Monitoring\ProbeExecutor;
use App\Application\Monitoring\RuntimeHealth;
use App\Infrastructure\Monitoring\ProbeResult;
use App\Jobs\ProbeMonitor;
use App\Models\AssetUsage;
use App\Models\Environment;
use App\Models\JobRun;
use App\Models\Monitor;
use App\Models\PolicyAssignment;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MonitoringFixture;
use Tests\TestCase;

class MonitoringSchedulerTest extends TestCase
{
    use MonitoringFixture, RefreshDatabase;

    public function test_shared_resource_is_scheduled_once_and_pausing_one_project_preserves_other(): void
    {
        [$org, $project, $fixture, , $version] = $this->graph();
        $other = Project::create(['organization_id' => $org->id, 'client_id' => $project->client_id, 'code' => 'OTHER', 'name' => 'Shared project', 'lifecycle' => 'active']);
        $env = Environment::create(['organization_id' => $org->id, 'project_id' => $other->id, 'kind' => 'production', 'display_name' => 'Production']);
        AssetUsage::create(['organization_id' => $org->id, 'asset_id' => $fixture->asset_id, 'project_id' => $other->id, 'environment_id' => $env->id, 'purpose' => 'public_endpoint']);
        foreach ([$project, $other] as $assigned) {
            PolicyAssignment::create(['organization_id' => $org->id, 'policy_version_id' => $version->id, 'resource_type' => 'project', 'resource_id' => $assigned->id, 'is_active' => true]);
        }
        $now = CarbonImmutable::parse('2026-10-04T00:00:00Z');
        $scheduler = app(MonitoringScheduler::class);
        $this->assertSame(2, $scheduler->tick($org, $now));
        $this->assertSame(0, $scheduler->tick($org, $now));
        $this->assertDatabaseCount('job_runs', 2);
        $this->assertDatabaseCount('jobs', 2);
        $http = Monitor::where('enabled', true)->where('kind', 'http')->sole();
        $this->assertSame(2, $http->projects()->count());
        $project->update(['lifecycle' => 'paused']);
        $scheduler->synchronize($org, $now);
        $this->assertSame(1, $http->activeProjects()->count());
        $this->assertSame(2, $http->projects()->count());
        $this->assertTrue($http->fresh()->enabled);
        $other->update(['lifecycle' => 'archived']);
        $scheduler->synchronize($org, $now);
        $this->assertFalse($http->fresh()->enabled);
        $this->assertSame(2, JobRun::where('state', 'cancelled')->count());
    }

    public function test_missed_slots_are_coalesced_and_worker_replay_never_fabricates_downtime(): void
    {
        [$org, $project, , , $version] = $this->graph();
        PolicyAssignment::create(['organization_id' => $org->id, 'policy_version_id' => $version->id, 'resource_type' => 'project', 'resource_id' => $project->id, 'is_active' => true]);
        $now = CarbonImmutable::parse('2026-10-04T00:00:00Z');
        $scheduler = app(MonitoringScheduler::class);
        $scheduler->tick($org, $now);
        $scheduler->tick($org, $now->addMinutes(10));
        $this->assertDatabaseCount('observations', 0);
        $this->assertSame(1, JobRun::where('state', 'coalesced')->count());
        $run = JobRun::where('kind', 'monitor.http')->where('state', 'queued')->sole();
        $this->assertSame(9, $run->missed_slots);
        $this->assertSame('fresh', app(RuntimeHealth::class)->snapshot($org, $now->addMinutes(10))['components']['scheduler']['state']);
        $this->assertSame('stale', app(RuntimeHealth::class)->snapshot($org, $now->addMinutes(14))['components']['scheduler']['state']);
        CarbonImmutable::setTestNow($now->addMinutes(10));
        try {
            $executor = new class extends ProbeExecutor
            {
                public function execute(Monitor $monitor): ProbeResult
                {
                    return new ProbeResult('pass', 'HTTP_OK', ['status_code' => 200], fake: true);
                }
            };
            $job = new ProbeMonitor($run->id);
            $job->handle(app(ObservationRecorder::class), app(IncidentEngine::class), $executor);
            $job->handle(app(ObservationRecorder::class), app(IncidentEngine::class), $executor);
            $this->assertDatabaseCount('observations', 1);
            $this->assertDatabaseCount('incidents', 0);
            $this->assertSame('up', Monitor::findOrFail($run->resource_id)->state);
            $this->assertSame('fresh', app(RuntimeHealth::class)->snapshot($org, $now->addMinutes(10))['components']['worker:probe']['state']);
            $this->assertSame('unknown', app(MonitoringHealth::class)->monitor(Monitor::findOrFail($run->resource_id), $now->addMinutes(14))['state']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_expired_worker_is_requeued_and_public_probe_gate_is_honest(): void
    {
        [$org, $project, $monitor, , $version] = $this->graph();
        PolicyAssignment::create(['organization_id' => $org->id, 'policy_version_id' => $version->id, 'resource_type' => 'project', 'resource_id' => $project->id, 'is_active' => true]);
        $now = CarbonImmutable::parse('2026-10-04T00:00:00Z');
        app(MonitoringScheduler::class)->tick($org, $now);
        $run = JobRun::where('kind', 'monitor.http')->sole();
        $run->update(['state' => 'running', 'leased_until' => $now->addSeconds(45), 'lease_owner' => 'expired']);
        app(MonitoringScheduler::class)->tick($org, $now->addSeconds(46));
        $this->assertSame('queued', $run->fresh()->state);
        $this->assertSame('LEASE_EXPIRED', $run->fresh()->last_error_code);
        $run->refresh()->update(['state' => 'running', 'attempts' => 3, 'leased_until' => $now->addSeconds(45)]);
        app(MonitoringScheduler::class)->tick($org, $now->addSeconds(47));
        $this->assertSame('failed', $run->fresh()->state);
        $this->assertSame('ATTEMPTS_EXHAUSTED', $run->fresh()->last_error_code);
        $this->assertSame('unknown', app(ProbeExecutor::class)->execute($monitor)->outcome);
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseCount('incidents', 0);
        $this->assertSame('not_configured', app(RuntimeHealth::class)->snapshot($org, $now)['components']['independent_watchdog']['state']);
    }
}
