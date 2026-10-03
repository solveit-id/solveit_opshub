<?php

namespace Tests\Feature;

use App\Application\Monitoring\IncidentActions;
use App\Application\Monitoring\IncidentEngine;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\OutboxEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\MonitoringFixture;
use Tests\TestCase;

class IncidentEngineTest extends TestCase
{
    use MonitoringFixture, RefreshDatabase;

    public function test_three_fail_two_success_dedup_unknown_break_and_recurrence(): void
    {
        [, , $monitor] = $this->graph();
        $at = CarbonImmutable::parse('2026-10-04T00:00:00Z');
        $this->sample($monitor, $at, 'fail');
        $this->assertSame('suspect', $monitor->fresh()->state);
        $this->sample($monitor, $at->addMinute(), 'unknown');
        $this->sample($monitor, $at->addMinutes(2), 'fail');
        $this->sample($monitor, $at->addMinutes(3), 'fail');
        $this->assertDatabaseCount('incidents', 0);
        $third = $this->sample($monitor, $at->addMinutes(4), 'fail');
        $incident = Incident::sole();
        $this->assertTrue($incident->first_failed_at->lessThan($incident->confirmed_down_at));
        app(IncidentEngine::class)->evaluate($third);
        $this->sample($monitor, $at->addMinutes(5), 'fail');
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->sample($monitor, $at->addMinutes(6), 'pass');
        $this->assertSame('recovering', $monitor->fresh()->state);
        $this->sample($monitor, $at->addMinutes(7), 'pass');
        $this->assertSame('resolved', $incident->fresh()->state);
        $this->assertTrue($incident->fresh()->first_recovery_sample_at->lessThan($incident->fresh()->confirmed_recovered_at));
        $this->assertDatabaseCount('outbox_events', 2);
        foreach ([8, 9, 10] as $minute) {
            $this->sample($monitor, $at->addMinutes($minute), 'fail');
        }
        $this->assertSame('open', $incident->fresh()->state);
        $this->assertDatabaseCount('incidents', 1);
    }

    public function test_maintenance_retains_evidence_and_alerts_when_window_ends(): void
    {
        [$org, , $monitor] = $this->graph();
        $at = CarbonImmutable::parse('2026-10-04T00:00:00Z');
        MaintenanceWindow::create(['organization_id' => $org->id, 'monitor_id' => $monitor->id, 'starts_at' => $at, 'ends_at' => $at->addMinutes(4), 'reason' => 'Planned maintenance', 'created_by_user_id' => User::factory()->create()->id]);
        foreach ([0, 1, 2] as $minute) {
            $this->sample($monitor, $at->addMinutes($minute), 'fail');
        }
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('observations', 3);
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertDatabaseHas('incident_transitions', ['suppressed' => true]);
        $this->sample($monitor, $at->addMinutes(4), 'fail');
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_three_episodes_in_thirty_minutes_coalesce_to_stability_warning(): void
    {
        [, , $monitor] = $this->graph();
        $at = CarbonImmutable::parse('2026-10-04T00:00:00Z');
        for ($episode = 0; $episode < 4; $episode++) {
            foreach (['fail', 'fail', 'fail', 'pass', 'pass'] as $minute => $outcome) {
                $this->sample($monitor, $at->addMinutes($episode * 5 + $minute), $outcome);
            }
        }
        $this->assertTrue(Incident::sole()->flapping);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'incident.stability_warning']);
        $this->assertDatabaseCount('observations', 20);
        $this->assertSame(1, OutboxEvent::where('event_type', 'incident.stability_warning')->count());
    }

    public function test_closure_requires_recovery_summary_and_new_episode_links_history(): void
    {
        [, , $monitor] = $this->graph();
        $at = CarbonImmutable::parse('2026-10-04T00:00:00Z');
        $actor = User::factory()->create();
        foreach (['fail', 'fail', 'fail'] as $minute => $outcome) {
            $this->sample($monitor, $at->addMinutes($minute), $outcome);
        }
        $incident = Incident::sole();
        $this->assertSame(3, $incident->observations()->count());
        try {
            app(IncidentActions::class)->change($incident, $actor, 'close', $incident->version, ['summary' => 'Unknown cause']);
            $this->fail('Failing rule must not close');
        } catch (ValidationException) {
            $this->assertSame('open', $incident->fresh()->state);
        }
        $this->sample($monitor, $at->addMinutes(3), 'pass');
        $this->sample($monitor, $at->addMinutes(4), 'pass');
        $incident->refresh();
        app(IncidentActions::class)->change($incident, $actor, 'close', $incident->version, ['summary' => 'Pulih; cause belum diketahui.']);
        foreach ([5, 6, 7] as $minute) {
            $this->sample($monitor, $at->addMinutes($minute), 'fail');
        }
        $this->assertDatabaseCount('incidents', 2);
        $this->assertDatabaseHas('incidents', ['previous_incident_id' => $incident->id, 'state' => 'open']);
    }
}
