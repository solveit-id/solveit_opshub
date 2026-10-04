<?php

namespace Tests\Feature;

use App\Infrastructure\Testing\MonitoringDemo;
use App\Jobs\DispatchPendingOutboxEvents;
use App\Models\Incident;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringVerticalDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_vertical_loop_runs_real_scheduler_worker_persistence_and_incident_services_under_fakes(): void
    {
        $report = app(MonitoringDemo::class)->run();
        $this->assertSame(['up', 'suspect', 'suspect', 'down', 'recovering', 'up'], $report['states']);
        $this->assertSame(1, $report['canonical_monitors']);
        $this->assertSame(6, $report['observations']);
        $this->assertTrue($report['all_fake']);
        $this->assertSame('closed', $report['incident_state']);
        $this->assertSame(2, $report['impacted_projects']);
        $this->assertSame(1, $report['down_events']);
        $this->assertSame(1, $report['recovery_events']);
        $this->assertSame('unknown', $report['stale_health']);
        $this->assertSame('stale', $report['stale_scheduler']);
        $this->assertSame('not_configured', $report['hosting_credentials']);
        $this->assertSame('not_configured', $report['telegram_delivery']);
        (new DispatchPendingOutboxEvents)->handle();
        $this->assertSame(2, OutboxEvent::where('status', 'pending')->count());
        $incident = Incident::sole();
        $this->assertTrue($incident->first_failed_at->lessThan($incident->confirmed_down_at));
        $this->assertSame('require_delivered_down_for_destination', OutboxEvent::where('event_type', 'incident.resolved')->sole()->payload['delivery_guard']);
    }

    public function test_demo_refuses_the_runtime_database_before_writing(): void
    {
        config(['database.connections.mysql.database' => 'solveit_opshub']);
        $this->expectException(\LogicException::class);
        app(MonitoringDemo::class)->run();
    }

    public function test_browser_fixture_can_recover_without_a_native_probe(): void
    {
        try {
            $demo = app(MonitoringDemo::class);
            $report = $demo->run(true);
            $this->assertSame(2, Incident::forOrganization($report['organization_id'])->count());
            $recovered = $demo->recover($report['organization_id']);
            $this->assertSame('up', $recovered['monitor_state']);
            $this->assertSame('resolved', $recovered['incident_state']);
            $this->assertTrue($recovered['fake']);
        } finally {
            @unlink(storage_path('app/qa-m1-login.json'));
        }
    }
}
