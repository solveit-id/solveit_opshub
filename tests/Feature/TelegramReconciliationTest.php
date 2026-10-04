<?php

namespace Tests\Feature;

use App\Application\RenewalFollowups\RenewalScheduler;
use App\Application\TelegramNotifications\DeliveryMaterializer;
use App\Application\TelegramNotifications\DeliveryReconciler;
use App\Application\TelegramNotifications\DeliverySender;
use App\Application\TelegramNotifications\NotificationDispatcher;
use App\Application\TelegramNotifications\OutboxWriter;
use App\Application\TelegramNotifications\TelegramConfiguration;
use App\Domain\IdentityAccess\Role;
use App\Infrastructure\Telegram\TelegramResult;
use App\Infrastructure\Telegram\TelegramTransport;
use App\Infrastructure\Testing\ScriptedTelegramTransport;
use App\Models\ClientFollowup;
use App\Models\Incident;
use App\Models\IncidentNotificationHistory;
use App\Models\Membership;
use App\Models\OutboxEvent;
use App\Models\TelegramBinding;
use App\Models\TelegramBot;
use App\Models\TelegramDelivery;
use App\Models\TelegramIntegrationFinding;
use App\Models\TelegramUpdateReceipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\MonitoringFixture;
use Tests\Support\RenewalFixture;
use Tests\Support\TelegramFixture;
use Tests\TestCase;

class TelegramReconciliationTest extends TestCase
{
    use MonitoringFixture, RefreshDatabase, RenewalFixture, TelegramFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-04T03:00:00Z'));
    }

    private function monitoringTelegram(): array
    {
        [$org, $project, $monitor] = $this->graph();
        $org = $org->fresh();
        $owner = User::factory()->create();
        Membership::create(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => Role::Owner, 'is_active' => true]);
        $fake = new ScriptedTelegramTransport;
        app()->instance(TelegramTransport::class, $fake);
        $configuration = app(TelegramConfiguration::class);
        $bot = $configuration->bot($org, $owner, $this->botData());
        $configuration->identity($org, $owner, $bot->version);
        $bot = $configuration->bot($org, $owner, [...$this->botData(), 'version' => $bot->fresh()->version, 'enabled' => true]);
        $dest = $configuration->destination($org, $owner, $this->destinationData([$project->id]));

        return [$org, $owner, $monitor, $project, $bot, $dest, $fake];
    }

    private function deliveryFixture($org, $owner, $dest): TelegramDelivery
    {
        app(TelegramConfiguration::class)->testIntent($org, $owner, $dest, $dest->fresh()->version);
        app(DeliveryMaterializer::class)->materialize($org);

        return TelegramDelivery::where('notification_kind', 'internal')->latest('id')->firstOrFail();
    }

    public function test_recovery_requires_actual_down_acceptance_for_same_destination_and_current_episode(): void
    {
        [$org, $owner, $monitor, $project, , $dest] = $this->monitoringTelegram();
        $other = app(TelegramConfiguration::class)->destination($org, $owner, [...$this->destinationData([$project->id]), 'chat_id' => '-1000000002']);
        $at = CarbonImmutable::now('UTC')->subMinutes(3);
        foreach ([0, 1, 2] as $minute) {
            $this->sample($monitor, $at->addMinutes($minute), 'fail');
        }
        $i = Incident::sole();
        app(DeliveryMaterializer::class)->materialize($org);
        $down = TelegramDelivery::where('telegram_destination_id', $dest->id)->firstOrFail();
        $unsent = TelegramDelivery::where('telegram_destination_id', $other->id)->firstOrFail();
        $this->assertSame('sent', app(DeliverySender::class)->send($down->id)->state);
        $this->assertTrue(app(DeliveryReconciler::class)->hasDown($i->id, 'destination:'.$dest->id));
        $this->assertFalse(app(DeliveryReconciler::class)->hasDown($i->id, 'destination:'.$other->id));
        $this->sample($monitor, $at->addMinutes(3), 'pass');
        $this->sample($monitor, $at->addMinutes(4), 'pass');
        $this->travelTo($at->addMinutes(5));
        app(DeliveryMaterializer::class)->materialize($org);
        $recovery = TelegramDelivery::whereIn('outbox_event_id', OutboxEvent::where('event_type', 'incident.resolved')->select('id'))->get();
        $this->assertCount(1, $recovery);
        $this->assertSame($dest->id, $recovery->first()->telegram_destination_id);
        $this->assertSame('sent', app(DeliverySender::class)->send($recovery->first()->id)->state);
        $this->assertNotNull(IncidentNotificationHistory::first()->recovery_delivery_id);
        $this->assertSame('superseded', app(DeliverySender::class)->send($unsent->id)->state);
        $summary = TelegramDelivery::where('notification_kind', 'recovered_summary')->firstOrFail();
        $this->assertStringContainsString('TELAH PULIH', $summary->text);
        $this->assertSame('sent', app(DeliverySender::class)->send($summary->id)->state);
        $this->assertFalse(app(DeliveryReconciler::class)->hasDown($i->id, 'destination:'.$other->id));
        foreach ([6, 7, 8] as $minute) {
            $this->sample($monitor, $at->addMinutes($minute), 'fail');
        }
        $this->travelTo($at->addMinutes(9));
        $this->assertFalse(app(DeliveryReconciler::class)->hasDown($i->id, 'destination:'.$dest->id));
        app(DeliveryMaterializer::class)->materialize($org);
        $newDown = TelegramDelivery::where('telegram_destination_id', $dest->id)->where('state', 'pending')->latest('id')->firstOrFail();
        $this->assertSame('sent', app(DeliverySender::class)->send($newDown->id)->state);
        $this->assertTrue(app(DeliveryReconciler::class)->hasDown($i->id, 'destination:'.$dest->id));
        $this->assertSame($newDown->id, IncidentNotificationHistory::where('recipient_reference', 'destination:'.$dest->id)->first()->down_delivery_id);
    }

    public function test_retry_after_jitter_backoff_window_and_five_attempt_cap_preserve_business_events(): void
    {
        [$org, $owner, , , , , $dest] = $this->telegramGraph();
        $d = $this->deliveryFixture($org, $owner, $dest);
        $fake = new ScriptedTelegramTransport([new TelegramResult('retrying', 'RATE_LIMITED', 429, retryAfter: 60), ...array_fill(0, 4, new TelegramResult('retrying', 'PROVIDER_UNAVAILABLE', 503))]);
        app()->instance(TelegramTransport::class, $fake);
        $now = CarbonImmutable::now('UTC');
        $r = app(DeliverySender::class)->send($d->id, $now);
        $this->assertSame('retrying', $r->state);
        $this->assertGreaterThanOrEqual(60, $now->diffInSeconds($r->available_at));
        $this->assertLessThanOrEqual(63, $now->diffInSeconds($r->available_at));
        app(DeliverySender::class)->send($d->id, $now->addSeconds(59));
        $this->assertCount(1, $fake->calls);
        for ($attempt = 2; $attempt <= 5; $attempt++) {
            $r = app(DeliverySender::class)->send($d->id, $r->available_at);
            $this->assertSame($attempt, $r->attempts);
        }
        $this->assertSame('failed', $r->state);
        $this->assertSame('RETRY_EXHAUSTED', $r->result_code);
        $this->assertCount(5, $fake->calls);
        $this->assertSame(1, OutboxEvent::count());
        $this->assertDatabaseCount('telegram_delivery_attempts', 5);
        $next = $this->deliveryFixture($org, $owner, $dest->fresh());
        $next->update(['first_attempt_at' => $now->subMinutes(15), 'attempts' => 1]);
        $this->assertSame('failed', app(DeliverySender::class)->send($next->id, $now)->state);
        $this->assertCount(5, $fake->calls);
    }

    public function test_timeout_is_visible_unknown_with_at_most_one_uncertain_retry(): void
    {
        [$org, $owner, , , , , $dest] = $this->telegramGraph();
        $d = $this->deliveryFixture($org, $owner, $dest);
        $fake = new ScriptedTelegramTransport(array_fill(0, 3, new TelegramResult('unknown', 'DELIVERY_UNCERTAIN')));
        app()->instance(TelegramTransport::class, $fake);
        $r = app(DeliverySender::class)->send($d->id);
        $this->assertSame('unknown', $r->state);
        $this->assertNull($r->message_id);
        $this->travelTo($r->available_at);
        app(NotificationDispatcher::class)->tick($org);
        $this->assertSame('retrying', $d->fresh()->state);
        $r = app(DeliverySender::class)->send($d->id);
        $this->assertSame('unknown', $r->state);
        $this->assertSame(2, $r->attempts);
        $this->assertTrue($r->uncertain);
        $this->travel(5)->minutes();
        app(NotificationDispatcher::class)->tick($org);
        app(DeliverySender::class)->send($d->id);
        $this->assertCount(2, $fake->calls);
        $this->assertSame('unknown', $d->fresh()->state);
        $this->actingAs($owner)->getJson('/api/v1/organizations/'.$org->id.'/notifications')->assertOk()->assertJsonFragment(['duplicate_possible' => true, 'state' => 'unknown']);
        $this->assertDatabaseHas('telegram_integration_findings', ['code' => 'DELIVERY_UNCERTAIN', 'state' => 'open']);
    }

    public function test_expired_lease_fences_late_provider_result_and_retains_uncertainty(): void
    {
        [$org, $owner, , , , , $dest] = $this->telegramGraph();
        $d = $this->deliveryFixture($org, $owner, $dest);
        $now = CarbonImmutable::now('UTC');
        app()->instance(TelegramTransport::class, new class($org, $now) implements TelegramTransport
        {
            public function __construct(private $org, private $now) {}

            public function request(TelegramBot $bot, string $method, array $payload = []): TelegramResult
            {
                app(NotificationDispatcher::class)->tick($this->org, $this->now->addSeconds(31));

                return new TelegramResult('sent', 'ACCEPTED_LATE', 200, messageId: 'fake-late', fake: true);
            }
        });
        $r = app(DeliverySender::class)->send($d->id, $now);
        $this->assertSame('unknown', $r->state);
        $this->assertNull($r->message_id);
        $this->assertTrue($r->uncertain);
        $this->assertSame(2, $r->lease_version);
        $this->assertDatabaseHas('telegram_delivery_attempts', ['telegram_delivery_id' => $d->id, 'outcome' => 'unknown', 'result_code' => 'LEASE_EXPIRED_UNCERTAIN']);
    }

    public function test_permanent_forbidden_disables_destination_and_owner_test_proves_repair(): void
    {
        [$org, $owner, , $project, , , $dest] = $this->telegramGraph();
        $d = $this->deliveryFixture($org, $owner, $dest);
        app()->instance(TelegramTransport::class, new ScriptedTelegramTransport([new TelegramResult('failed', 'DESTINATION_FORBIDDEN', 403)]));
        $this->assertSame('failed', app(DeliverySender::class)->send($d->id)->state);
        $this->assertFalse($dest->fresh()->enabled);
        $finding = TelegramIntegrationFinding::sole();
        $base = '/api/v1/organizations/'.$org->id.'/notifications';
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('data.health.state', 'degraded');
        $this->postJson($base.'/findings/'.$finding->id.'/acknowledge', ['version' => $finding->version])->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson($base.'/findings/'.$finding->id.'/acknowledge', ['version' => $finding->version])->assertOk();
        $this->postJson($base.'/findings/'.$finding->id.'/acknowledge', ['version' => $finding->version])->assertConflict();
        app(TelegramConfiguration::class)->destination($org, $owner, [...$this->destinationData([$project->id]), 'version' => $dest->fresh()->version], $dest);
        $test = $this->deliveryFixture($org, $owner, $dest->fresh());
        app()->instance(TelegramTransport::class, new ScriptedTelegramTransport);
        $this->assertSame('sent', app(DeliverySender::class)->send($test->id)->state);
        $this->assertSame('resolved', $finding->fresh()->state);
        $this->assertSame($test->id, $finding->fresh()->verified_delivery_id);
        $this->assertSame('failed', $d->fresh()->state);
    }

    public function test_quiet_warning_goes_to_one_daily_digest_while_critical_remains_immediate(): void
    {
        $night = CarbonImmutable::parse('2026-10-04T15:30:00Z');
        $this->travelTo($night);
        [$org, , , $project, , , $dest, $fake] = $this->telegramGraph();
        foreach (['warning', 'critical'] as $index => $severity) {
            app(OutboxWriter::class)->record($org, 'telegram.notification.requested', 'fixture', $index + 1, 1, ['severity' => $severity, 'impacted_project_ids' => [$project->id]]);
        }
        app(DeliveryMaterializer::class)->materialize($org);
        $warning = TelegramDelivery::where('severity', 'warning')->firstOrFail();
        $critical = TelegramDelivery::where('severity', 'critical')->firstOrFail();
        $this->assertSame('pending', app(DeliverySender::class)->send($warning->id)->state);
        $this->assertSame('QUIET_HOURS', $warning->fresh()->result_code);
        $this->assertSame('sent', app(DeliverySender::class)->send($critical->id)->state);
        $this->assertCount(2, $fake->calls);
        $this->assertTrue($warning->fresh()->available_at->equalTo(CarbonImmutable::parse('2026-10-05T01:00:00Z')));
        $this->travelTo(CarbonImmutable::parse('2026-10-05T01:00:00Z'));
        app(NotificationDispatcher::class)->tick($org);
        app(NotificationDispatcher::class)->tick($org);
        $digest = TelegramDelivery::where('notification_kind', 'digest')->firstOrFail();
        $this->assertDatabaseCount('telegram_digest_slots', 1);
        $this->assertSame('superseded', $warning->fresh()->state);
        $this->assertSame($digest->id, $warning->fresh()->coalesced_into_id);
        $this->assertStringContainsString('QUIET event', $digest->text);
        $this->assertStringContainsString('unsupported', $digest->text);
        $this->assertSame('sent', app(DeliverySender::class)->send($digest->id)->state);
    }

    public function test_current_source_is_refreshed_and_cancelled_followup_does_not_send_old_template(): void
    {
        [$org, , $service] = $this->telegramGraph();
        app(RenewalScheduler::class)->tick($org);
        app(DeliveryMaterializer::class)->materialize($org);
        $old = TelegramDelivery::where('notification_kind', 'internal')->firstOrFail();
        $service->update(['service_name' => 'CURRENT service', 'version' => $service->version + 1]);
        $this->assertSame('superseded', app(DeliverySender::class)->send($old->id)->state);
        $current = TelegramDelivery::where('notification_kind', 'internal')->where('state', 'pending')->latest('id')->firstOrFail();
        $this->assertStringContainsString('CURRENT service', $current->text);
        $this->assertSame(2, $current->revision);
        $this->assertSame('sent', app(DeliverySender::class)->send($current->id)->state);
        $f = ClientFollowup::firstOrFail();
        $f->update(['state' => 'cancelled', 'version' => $f->version + 1]);
        $body = TelegramDelivery::where('notification_kind', 'client_template')->where('revision', 2)->firstOrFail();
        $this->assertSame('cancelled', app(DeliverySender::class)->send($body->id)->state);
        $this->assertDatabaseCount('contact_attempts', 0);
    }

    public function test_storm_preserves_individual_incidents_and_current_batch_history_excludes_recovered(): void
    {
        [$org, , $monitor, $project] = $this->monitoringTelegram();
        $now = CarbonImmutable::now('UTC');
        for ($n = 0; $n < 100; $n++) {
            $i = Incident::create(['organization_id' => $org->id, 'monitor_id' => $monitor->id, 'state' => 'open', 'severity' => 'critical', 'reason_code' => 'FAKE_STORM', 'first_failed_at' => $now, 'confirmed_down_at' => $now, 'last_failed_at' => $now]);
            $i->projects()->attach($project->id);
            app(OutboxWriter::class)->record($org, 'incident.opened', 'incident', $i->id, 1, ['severity' => 'critical', 'impacted_project_ids' => [$project->id]]);
        }
        app(DeliveryMaterializer::class)->materialize($org);
        $batch = TelegramDelivery::where('notification_kind', 'coalesced')->firstOrFail();
        $first = Incident::firstOrFail();
        $first->update(['state' => 'resolved', 'confirmed_recovered_at' => $now]);
        $r = app(DeliverySender::class)->send($batch->id);
        $this->assertSame('sent', $r->state);
        $this->assertStringContainsString('Incident aktif: 99', $r->text);
        $this->assertDatabaseCount('incidents', 100);
        $this->assertDatabaseCount('outbox_events', 100);
        $this->assertDatabaseCount('incident_notification_histories', 99);
        $this->assertSame(1, DB::table('telegram_delivery_attempts')->count());
        $this->assertFalse(app(DeliveryReconciler::class)->hasDown($first->id, $batch->recipient_reference));
    }

    public function test_invalid_token_blocks_automatic_retry_until_owner_identity_and_test_repair(): void
    {
        [$org, $owner, , , , $bot, $dest] = $this->telegramGraph();
        $d = $this->deliveryFixture($org, $owner, $dest);
        app()->instance(TelegramTransport::class, new ScriptedTelegramTransport([new TelegramResult('failed', 'TOKEN_INVALID', 401)]));
        $this->assertSame('failed', app(DeliverySender::class)->send($d->id)->state);
        $this->assertFalse($bot->fresh()->enabled);
        $this->assertNull($bot->fresh()->identity_verified_at);
        $this->assertDatabaseHas('telegram_integration_findings', ['code' => 'TOKEN_INVALID', 'state' => 'open']);
        $this->assertSame(0, app(NotificationDispatcher::class)->tick($org));
        app()->instance(TelegramTransport::class, new ScriptedTelegramTransport);
        $configuration = app(TelegramConfiguration::class);
        $configuration->identity($org, $owner, $bot->fresh()->version);
        $configuration->bot($org, $owner, [...$this->botData(), 'version' => $bot->fresh()->version, 'enabled' => true]);
        $repair = $this->deliveryFixture($org, $owner, $dest->fresh());
        $this->assertSame('sent', app(DeliverySender::class)->send($repair->id)->state);
        $this->assertDatabaseHas('telegram_integration_findings', ['code' => 'TOKEN_INVALID', 'state' => 'resolved', 'verified_delivery_id' => $repair->id]);
        $this->assertSame('failed', $d->fresh()->state);
    }

    public function test_dispatcher_prioritizes_critical_and_backlog_stays_visible_until_receipt_processed(): void
    {
        [$org, , , $project, , $bot] = $this->telegramGraph();
        foreach (['warning', 'critical'] as $n => $severity) {
            app(OutboxWriter::class)->record($org, 'telegram.notification.requested', 'fixture', $n, 1, ['severity' => $severity, 'impacted_project_ids' => [$project->id]]);
        }
        $r = TelegramUpdateReceipt::create(['organization_id' => $org->id, 'telegram_bot_id' => $bot->id, 'identity_version' => $bot->identity_version, 'update_id' => 900, 'encrypted_payload' => Crypt::encryptString('{}'), 'received_at' => now('UTC')->subMinutes(4), 'status' => 'pending']);
        app(NotificationDispatcher::class)->tick($org);
        $this->assertDatabaseHas('telegram_integration_findings', ['code' => 'WEBHOOK_BACKLOG', 'state' => 'open']);
        $sendJobs = DB::table('jobs')->orderBy('id')->get()->filter(fn ($j) => str_contains($j->payload, 'SendTelegramDelivery'));
        $this->assertSame('critical', $sendJobs->first()->queue);
        $this->assertTrue($sendJobs->contains(fn ($j) => $j->queue === 'notification'));
        $r->update(['status' => 'processed', 'processed_at' => now('UTC')]);
        app(NotificationDispatcher::class)->tick($org);
        $this->assertDatabaseHas('telegram_integration_findings', ['code' => 'WEBHOOK_BACKLOG', 'state' => 'resolved']);
        $this->assertSame(0, DB::table('telegram_delivery_attempts')->count());
    }

    public function test_private_destination_and_binding_share_actual_chat_rate_limit_and_history_privacy(): void
    {
        [$org, $owner, , $project, , $bot] = $this->telegramGraph();
        $dest = app(TelegramConfiguration::class)->destination($org, $owner, [...$this->destinationData([$project->id]), 'chat_id' => '123456', 'chat_type' => 'private', 'member_user_id' => $owner->id]);
        $d = $this->deliveryFixture($org, $owner, $dest);
        $this->assertSame('sent', app(DeliverySender::class)->send($d->id)->state);
        $binding = TelegramBinding::create(['organization_id' => $org->id, 'telegram_bot_id' => $bot->id, 'user_id' => $owner->id, 'telegram_user_id' => '123456', 'chat_id' => '123456', 'identity_version' => $bot->identity_version, 'enabled' => true, 'confirmed_at' => now('UTC')]);
        $other = $d->replicate(['state', 'attempts', 'message_id', 'sent_at', 'first_attempt_at', 'last_attempt_at', 'result_code']);
        $other->fill(['telegram_destination_id' => null, 'binding_id' => $binding->id, 'notification_kind' => 'private_reply', 'recipient_reference' => 'private:123456', 'available_at' => now('UTC')])->save();
        $this->assertSame('pending', app(DeliverySender::class)->send($other->id)->state);
        $this->assertSame('LOCAL_RATE_LIMIT', $other->fresh()->result_code);
        $operator = User::factory()->create();
        Membership::create(['organization_id' => $org->id, 'user_id' => $operator->id, 'role' => Role::Operator, 'is_active' => true]);
        $project->update(['internal_pic_user_id' => $operator->id]);
        $this->actingAs($operator)->getJson('/api/v1/organizations/'.$org->id.'/notifications')->assertOk()->assertJsonCount(0, 'data.deliveries');
        $this->actingAs($owner)->getJson('/api/v1/organizations/'.$org->id.'/notifications')->assertJsonFragment(['id' => $d->id, 'state' => 'sent']);
        $this->assertDatabaseCount('telegram_delivery_attempts', 1);
    }

    public function test_digest_refreshes_followup_data_and_recovered_critical_before_provider_io(): void
    {
        [$org, , $service] = $this->telegramGraph();
        app(RenewalScheduler::class)->tick($org);
        app(NotificationDispatcher::class)->tick($org);
        $digest = TelegramDelivery::where('notification_kind', 'digest')->firstOrFail();
        $this->assertStringContainsString('Hosting Fixture', $digest->text);
        ClientFollowup::firstOrFail()->update(['state' => 'cancelled']);
        $service->update(['service_name' => 'new name']);
        $r = app(DeliverySender::class)->send($digest->id);
        $this->assertSame('sent', $r->state);
        $this->assertStringNotContainsString('Hosting Fixture', $r->text);
        $this->assertStringContainsString('Total prioritas: 0', $r->text);
        [$org2, , $monitor, $project] = $this->monitoringTelegram();
        $incident = Incident::create(['organization_id' => $org2->id, 'monitor_id' => $monitor->id, 'state' => 'open', 'severity' => 'critical', 'reason_code' => 'FAKE_DIGEST', 'first_failed_at' => now('UTC'), 'confirmed_down_at' => now('UTC'), 'last_failed_at' => now('UTC')]);
        $incident->projects()->attach($project->id);
        app(NotificationDispatcher::class)->tick($org2);
        $digest2 = TelegramDelivery::forOrganization($org2)->where('notification_kind', 'digest')->firstOrFail();
        $this->assertStringContainsString('CRITICAL incident #'.$incident->id, $digest2->text);
        $incident->update(['state' => 'resolved', 'confirmed_recovered_at' => now('UTC')]);
        $r2 = app(DeliverySender::class)->send($digest2->id);
        $this->assertSame('sent', $r2->state);
        $this->assertStringNotContainsString('CRITICAL incident #'.$incident->id, $r2->text);
        $this->assertSame([], $r2->down_incident_ids);
        $this->assertFalse(app(DeliveryReconciler::class)->hasDown($incident->id, $r2->recipient_reference));
    }
}
