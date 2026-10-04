<?php

namespace Tests\Feature;

use App\Application\RenewalFollowups\RenewalScheduler;
use App\Application\TelegramNotifications\DeliveryMaterializer;
use App\Application\TelegramNotifications\DeliverySender;
use App\Application\TelegramNotifications\OutboxWriter;
use App\Application\TelegramNotifications\TelegramMessages;
use App\Application\TelegramNotifications\TelegramRateLimiter;
use App\Infrastructure\Telegram\NativeTelegramTransport;
use App\Infrastructure\Telegram\TelegramResult;
use App\Infrastructure\Telegram\TelegramTransport;
use App\Infrastructure\Testing\ScriptedTelegramTransport;
use App\Models\AssetUsage;
use App\Models\ClientFollowup;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\TelegramDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RenewalFixture;
use Tests\Support\TelegramFixture;
use Tests\TestCase;

class TelegramDeliveryTest extends TestCase
{
    use RefreshDatabase, RenewalFixture, TelegramFixture;

    public function test_current_renewal_header_and_plaintext_are_separate_durable_and_idempotent(): void
    {
        $now = CarbonImmutable::parse('2026-10-04T03:00:00Z');
        $this->travelTo($now);
        [$org, , , , , , , $fake] = $this->telegramGraph();
        app(RenewalScheduler::class)->tick($org);
        $this->assertSame('2026-10-04 03:00:00', DB::table('outbox_events')->where('event_type', 'renewal.reminder')->value('available_at'));
        $m = app(DeliveryMaterializer::class);
        $this->assertSame(2, $m->materialize($org));
        $this->assertSame(0, $m->materialize($org));
        $header = TelegramDelivery::where('notification_kind', 'internal')->firstOrFail();
        $body = TelegramDelivery::where('notification_kind', 'client_template')->firstOrFail();
        $this->assertStringContainsString('WIB', $header->text);
        $this->assertStringContainsString('PIC:', $header->text);
        $this->assertStringContainsString('[FAKE TESTING]', $header->text);
        $this->assertStringNotContainsString('Event:', $body->text);
        $this->assertNull($body->reply_markup);
        $this->assertSame($header->id, $body->parent_delivery_id);
        $this->assertSame('pending', app(DeliverySender::class)->send($body->id)->state);
        $this->assertSame('sent', app(DeliverySender::class)->send($header->id)->state);
        $this->assertNotNull($header->fresh()->message_id);
        app(DeliverySender::class)->send($header->id);
        $this->assertSame(2, count($fake->calls)); // identity and one actual fake delivery.
        $this->assertSame('sent', app(DeliverySender::class)->send($body->id)->state);
        $this->assertSame('open', ClientFollowup::first()->state);
        $this->assertDatabaseCount('contact_attempts', 0);
    }

    public function test_shared_scope_never_leaks_other_project_or_client_template(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04T03:00:00Z'));
        [$org, $owner, $service, $project] = $this->telegramGraph();
        $hidden = Project::create(['organization_id' => $org->id, 'client_id' => $project->client_id, 'code' => 'HIDDEN', 'name' => 'Hidden outside destination', 'lifecycle' => 'active', 'internal_pic_user_id' => $owner->id]);
        AssetUsage::create(['organization_id' => $org->id, 'project_id' => $hidden->id, 'asset_id' => $service->asset_id, 'purpose' => 'shared']);
        app(RenewalScheduler::class)->tick($org);
        app(DeliveryMaterializer::class)->materialize($org);
        $this->assertSame(1, TelegramDelivery::count());
        $text = TelegramDelivery::first()->text;
        $this->assertStringNotContainsString($hidden->name, $text);
        $this->assertStringContainsString('template_scope_incomplete', $text);
    }

    public function test_unicode_segments_preserve_codepoints_and_standalone_dashboard_url(): void
    {
        $m = app(TelegramMessages::class);
        $parts = $m->segment(str_repeat('😀', 4000)."\nDashboard: https://dashboard.example/organizations/1/renewals");
        $this->assertCount(3, $parts);
        foreach ($parts as $part) {
            $this->assertTrue(mb_check_encoding($part));
            $this->assertLessThanOrEqual(3500, $m->units($part));
        }
        $this->assertSame(4000, substr_count(implode('', $parts), '😀'));
        $this->assertStringContainsString('https://dashboard.example/organizations/1/renewals', $parts[2]);
        $this->assertStringNotContainsString('sensitive', $m->clean('token=sensitive https://example.invalid/private <script>hi</script>'));
    }

    public function test_storm_coalesces_one_hundred_events_and_retains_the_individual_records(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04T03:00:00Z'));
        [$org, , , $project] = $this->telegramGraph();
        for ($i = 1; $i <= 100; $i++) {
            app(OutboxWriter::class)->record($org, 'telegram.notification.requested', 'fixture_incident', $i, 1, ['severity' => 'critical', 'impacted_project_ids' => [$project->id]]);
        }
        app(DeliveryMaterializer::class)->materialize($org);
        $this->assertSame(100, OutboxEvent::count());
        $this->assertSame(100, TelegramDelivery::where('state', 'superseded')->count());
        $batch = TelegramDelivery::where('state', 'pending')->firstOrFail();
        $this->assertSame('coalesced', $batch->notification_kind);
        $this->assertCount(100, $batch->event_ids);
        $this->assertLessThanOrEqual(3500, app(TelegramMessages::class)->units($batch->text));
    }

    public function test_group_private_and_bot_rate_caps_reserve_attempts_not_just_sent_messages(): void
    {
        $now = CarbonImmutable::parse('2026-10-04T03:00:00.123456Z');
        $this->travelTo($now);
        [$org, , , $project, , $bot, $dest] = $this->telegramGraph();
        for ($i = 1; $i <= 20; $i++) {
            $event = app(OutboxWriter::class)->record($org, 'telegram.test_requested', 'fixture', $i, 1, ['destination_id' => $dest->id, 'severity' => 'critical', 'impacted_project_ids' => [$project->id]]);
            $d = TelegramDelivery::create(['organization_id' => $org->id, 'telegram_bot_id' => $bot->id, 'telegram_destination_id' => $dest->id, 'outbox_event_id' => $event->id, 'recipient_reference' => 'destination:'.$dest->id, 'notification_kind' => 'internal', 'project_ids' => [$project->id], 'text' => 'fixture', 'severity' => 'critical', 'priority' => 0, 'available_at' => $now]);
            $result = app(DeliverySender::class)->send($d->id, $now);
            $this->assertSame($i <= 15 ? 'sent' : 'pending', $result->state);
        }
        $this->assertDatabaseCount('telegram_delivery_attempts', 15);
        $this->assertTrue(TelegramDelivery::latest('id')->first()->available_at->equalTo($now->addMinute()));
        $dest->update(['chat_type' => 'private']);
        $last = TelegramDelivery::latest('id')->first();
        $this->assertTrue(app(TelegramRateLimiter::class)->available($bot, $last, $now)->equalTo($now->addSecond()));
        // Distinct private recipient ignores group window, but global 20/sec still applies.
        for ($i = 1; $i <= 5; $i++) {
            DB::table('telegram_delivery_attempts')->insert(['telegram_bot_id' => $bot->id, 'telegram_delivery_id' => $last->id, 'recipient_reference' => 'binding:'.$i, 'attempt' => $i, 'started_at' => $now->format('Y-m-d H:i:s.u')]);
        }
        $last->recipient_reference = 'binding:new';
        $this->assertTrue(app(TelegramRateLimiter::class)->available($bot, $last, $now)->equalTo($now->addSecond()));
        $this->assertTrue(app(TelegramRateLimiter::class)->available($bot, $last, $now->addSecond())->equalTo($now->addSecond()));
    }

    public function test_provider_errors_are_structured_and_live_disabled_never_sends_or_claims_sent(): void
    {
        [$org, , , $project, , $bot, $dest] = $this->telegramGraph();
        $native = app(NativeTelegramTransport::class);
        $this->assertSame('LIVE_DISABLED', $native->request($bot, 'sendMessage', ['text' => 'fixture'])->code);
        foreach ([429 => 'RATE_LIMITED', 500 => 'PROVIDER_UNAVAILABLE', 401 => 'TOKEN_INVALID', 403 => 'DESTINATION_FORBIDDEN', 400 => 'PAYLOAD_INVALID'] as $http => $code) {
            $this->assertSame($code, $native->interpret('sendMessage', $http, null)->code);
        }
        $this->assertSame(90, $native->interpret('sendMessage', 429, ['parameters' => ['retry_after' => 90]])->retryAfter);
        $this->assertSame('unknown', $native->interpret('sendMessage', 200, ['ok' => true, 'result' => []])->outcome);
        $this->assertSame('sent', $native->interpret('sendMessage', 200, ['ok' => true, 'result' => ['message_id' => 42]])->outcome);
        app(OutboxWriter::class)->record($org, 'telegram.test_requested', 'fixture', 1, 1, ['destination_id' => $dest->id, 'severity' => 'critical', 'impacted_project_ids' => [$project->id]]);
        app(DeliveryMaterializer::class)->materialize($org);
        $d = TelegramDelivery::firstOrFail();
        app()->instance(TelegramTransport::class, new ScriptedTelegramTransport([new TelegramResult('sent', 'INVALID_ACCEPTANCE', 200)]));
        $this->assertSame('unknown', app(DeliverySender::class)->send($d->id)->state);
        $this->assertNull($d->fresh()->message_id);
        app()->instance(TelegramTransport::class, $native);
        $d->fresh()->update(['state' => 'pending']);
        $this->assertSame('pending', app(DeliverySender::class)->send($d->id)->state);
        $this->assertSame(1, $d->fresh()->attempts);
    }
}
