<?php

namespace Tests\Feature;

use App\Application\RenewalFollowups\RenewalScheduler;
use App\Models\AssetUsage;
use App\Models\ClientFollowup;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\RenewalReminder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\RenewalFixture;
use Tests\TestCase;

class RenewalSchedulerTest extends TestCase
{
    use RefreshDatabase, RenewalFixture;

    public function test_late_entry_sends_only_highest_threshold_and_shared_resource_is_canonical(): void
    {
        [$org, , $service, $project] = $this->renewalGraph();
        $other = Project::create(['organization_id' => $org->id, 'client_id' => $project->client_id, 'code' => 'SHARED', 'name' => 'Shared fixture', 'lifecycle' => 'active']);
        AssetUsage::create(['organization_id' => $org->id, 'asset_id' => $service->asset_id, 'project_id' => $other->id, 'purpose' => 'renewal']);
        $scheduler = app(RenewalScheduler::class);
        $now = CarbonImmutable::parse('2026-10-04T03:00:00Z');
        $this->assertCount(1, $scheduler->tick($org, $now));
        $this->assertSame(7, RenewalReminder::where('state', 'pending')->sole()->threshold);
        $this->assertSame(3, RenewalReminder::where('state', 'skipped')->count());
        $this->assertSame([$project->id, $other->id], OutboxEvent::sole()->payload['impacted_project_ids']);
        $this->assertCount(0, $scheduler->tick($org, $now));
        $this->assertSame(1, ClientFollowup::count());
        $this->assertSame('critical', ClientFollowup::sole()->severity);
        $this->assertSame('pending', OutboxEvent::sole()->status);
    }

    public function test_calendar_boundary_not_server_day_and_waiting_does_not_cancel_critical(): void
    {
        [$org] = $this->renewalGraph(['expiry_date' => '2026-10-11']);
        $scheduler = app(RenewalScheduler::class);
        $scheduler->tick($org, CarbonImmutable::parse('2026-10-03T16:59:59Z'));
        $this->assertSame(14, RenewalReminder::where('state', 'pending')->sole()->threshold);
        ClientFollowup::sole()->update(['state' => 'waiting_client', 'next_followup_at' => '2026-10-03 17:00:00']);
        $scheduler->tick($org, CarbonImmutable::parse('2026-10-03T17:00:00Z'));
        $this->assertDatabaseHas('renewal_reminders', ['threshold' => 7, 'state' => 'pending']);
        $this->assertSame('waiting_client', ClientFollowup::sole()->state);
        $this->assertTrue(ClientFollowup::sole()->overdue(CarbonImmutable::parse('2026-10-04T00:00:00Z')));
    }

    public function test_unknown_expiry_is_one_verification_task_and_no_fictitious_date(): void
    {
        [$org] = $this->renewalGraph(['date_precision' => 'unknown', 'expiry_date' => null, 'source_timezone' => null]);
        $scheduler = app(RenewalScheduler::class);
        $scheduler->tick($org);
        $scheduler->tick($org);
        $this->assertSame('verify_expiry', ClientFollowup::sole()->purpose);
        $this->assertSame('renewal.verification_required', OutboxEvent::sole()->event_type);
        $this->assertArrayNotHasKey('expiry_date', OutboxEvent::sole()->payload);
    }

    public function test_escalation_is_bounded_and_snooze_requires_reason_expiry_and_cap(): void
    {
        [$org, $owner, $service] = $this->renewalGraph();
        $scheduler = app(RenewalScheduler::class);
        $now = CarbonImmutable::parse('2026-10-04T03:00:00Z');
        $scheduler->tick($org, $now);
        $scheduler->tick($org, $now->addMinutes(15));
        $scheduler->tick($org, $now->addMinutes(61));
        $scheduler->tick($org, $now->addMinutes(62));
        $this->assertSame(2, RenewalReminder::where('kind', 'escalation')->count());
        $f = ClientFollowup::sole();
        $scheduler->snooze($org, $owner, $service, $f->version, $now->addHours(24), 'Client checking', $now);
        $this->assertCount(0, $scheduler->tick($org, $now->addHours(1)));
        $this->expectException(ValidationException::class);
        $scheduler->snooze($org, $owner, $service, $f->fresh()->version, $now->addHours(25), 'Too long', $now);
    }
}
