<?php

namespace Tests\Feature;

use App\Application\RenewalFollowups\RenewalScheduler;
use App\Application\TelegramNotifications\DeliveryMaterializer;
use App\Application\TelegramNotifications\DeliverySender;
use App\Application\TelegramNotifications\TelegramUpdateProcessor;
use App\Infrastructure\Telegram\TelegramSecretResolver;
use App\Models\ClientFollowup;
use App\Models\ContactAttempt;
use App\Models\OutboxEvent;
use App\Models\RenewalCycle;
use App\Models\RenewalReminder;
use App\Models\TelegramBinding;
use App\Models\TelegramCallbackReference;
use App\Models\TelegramDelivery;
use App\Models\TelegramUpdateReceipt;
use App\Models\TemplateDraft;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RenewalFixture;
use Tests\Support\TelegramFixture;
use Tests\TestCase;

class RenewalTelegramDemoTest extends TestCase
{
    use RefreshDatabase, RenewalFixture, TelegramFixture;

    public function test_full_h14_client_loop_with_fake_telegram_and_dashboard_confirmation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04T03:00:00Z'));
        [$org, $owner, $service, , $contact, $bot, $dest, $fake] = $this->telegramGraph(['expiry_date' => '2026-10-18']);
        $this->assertFalse(config('opshub.live_connectors_enabled'));
        $this->assertFalse(config('opshub.public_probes_enabled'));
        app()->instance(TelegramSecretResolver::class, new class implements TelegramSecretResolver
        {
            public function resolve(string $reference): string
            {
                return 'fictitious-demo-webhook';
            }
        });
        $base = '/api/v1/organizations/'.$org->id;
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $intent = $this->postJson($base.'/telegram-binding')->assertCreated()->json('data');
        $payload = ['update_id' => 1, 'message' => ['message_id' => 1, 'from' => ['id' => 700001, 'is_bot' => false], 'chat' => ['id' => 700001, 'type' => 'private'], 'text' => $intent['command']]];
        $webhook = '/api/telegram/webhook/'.$bot->id;
        $this->postJson($webhook, $payload)->assertForbidden();
        $receipt = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'fictitious-demo-webhook')->postJson($webhook, $payload)->assertOk()->json('receipt_id');
        $this->postJson($webhook, $payload)->assertOk()->assertJsonPath('receipt_id', $receipt);
        app(TelegramUpdateProcessor::class)->process($receipt);
        $this->assertDatabaseCount('telegram_bindings', 0);
        $this->postJson($base.'/telegram-binding/'.$intent['intent_id'].'/confirm', ['telegram_user_id' => '700001', 'private_identity_confirmed' => true])->assertOk();
        $this->assertTrue(TelegramBinding::sole()->enabled);
        $this->assertDatabaseCount('telegram_update_receipts', 1);
        app(RenewalScheduler::class)->tick($org);
        app(RenewalScheduler::class)->tick($org);
        $followup = ClientFollowup::sole();
        $oldCycle = $followup->cycle;
        $this->assertDatabaseHas('renewal_reminders', ['renewal_cycle_id' => $oldCycle->id, 'threshold' => 14, 'state' => 'pending']);
        $this->assertDatabaseCount('client_followups', 1);
        app(DeliveryMaterializer::class)->materialize($org);
        $event = OutboxEvent::where('event_type', 'renewal.reminder')->sole();
        $header = TelegramDelivery::where('outbox_event_id', $event->id)->where('notification_kind', 'internal')->sole();
        $body = TelegramDelivery::where('outbox_event_id', $event->id)->where('notification_kind', 'client_template')->sole();
        $this->assertSame($header->id, $body->parent_delivery_id);
        $this->assertStringContainsString('Event:', $header->text);
        $this->assertStringNotContainsString('Event:', $body->text);
        $this->assertNull($body->reply_markup);
        $this->assertSame('sent', app(DeliverySender::class)->send($header->id)->state);
        $this->assertSame('sent', app(DeliverySender::class)->send($body->id)->state);
        $this->assertSame($dest->chat_id, $fake->calls[array_key_last($fake->calls)]['payload']['chat_id']);
        $ref = $header->reply_markup['inline_keyboard'][1][0]['callback_data'];
        $callback = ['update_id' => 2, 'callback_query' => ['id' => 'fake-demo-callback', 'from' => ['id' => 700001, 'is_bot' => false], 'message' => ['message_id' => 9, 'chat' => ['id' => (int) $dest->chat_id, 'type' => 'supergroup']], 'data' => $ref]];
        $callbackReceipt = $this->postJson($webhook, $callback)->assertOk()->json('receipt_id');
        app(TelegramUpdateProcessor::class)->process($callbackReceipt);
        $afterCallbackVersion = $followup->fresh()->version;
        $callback['update_id'] = 3;
        $callback['callback_query']['id'] = 'fake-demo-replay';
        $replay = $this->postJson($webhook, $callback)->assertOk()->json('receipt_id');
        app(TelegramUpdateProcessor::class)->process($replay);
        $this->assertSame($afterCallbackVersion, $followup->fresh()->version);
        $this->assertNotNull(TelegramCallbackReference::where('reference_hash', hash('sha256', $ref))->sole()->used_at);
        $furl = $base.'/follow-ups/'.$followup->id;
        $draft = $this->postJson($furl.'/drafts', ['version' => $afterCallbackVersion])->assertOk()->json('data');
        $this->assertSame('TPL-01', $draft['template_key']);
        $this->assertSame('ready', $draft['draft_status']);
        $this->assertSame('open', $followup->fresh()->state);
        $this->assertDatabaseCount('contact_attempts', 0);
        $this->withHeader('Idempotency-Key', 'full-demo-contact')->postJson($furl.'/contact', ['version' => $afterCallbackVersion, 'template_draft_id' => $draft['id'], 'contact_id' => $contact->id, 'sent_at' => now('UTC')->subMinute()->toIso8601String(), 'manual_channel' => 'manual_fictitious', 'next_followup_at' => now('UTC')->addDay()->toIso8601String()])->assertOk()->assertJsonPath('data.state', 'contacted');
        $historicBody = ContactAttempt::sole()->sent_body;
        $this->assertSame($draft['rendered_body'], $historicBody);
        $this->withHeader('Idempotency-Key', 'full-demo-wait')->postJson($furl.'/waiting_client', ['version' => $followup->fresh()->version, 'next_followup_at' => now('UTC')->addDay()->toIso8601String()])->assertOk()->assertJsonPath('data.state', 'waiting_client');
        $this->withHeader('Idempotency-Key', 'full-demo-confirm')->postJson($furl.'/client_confirmed', ['version' => $followup->fresh()->version, 'response_summary' => 'Fictitious client reports payment'])->assertOk()->assertJsonPath('data.state', 'client_confirmed');
        $this->assertSame('active', $oldCycle->fresh()->state);
        $this->assertSame('unknown', $service->fresh()->payment_status);
        $verify = ['version' => $followup->fresh()->version, 'subscription_version' => $service->fresh()->version, 'date_precision' => 'date', 'expiry_date' => '2027-10-18', 'source_timezone' => 'Asia/Jakarta', 'source' => 'manual_fictitious_provider_review', 'evidence_reference' => 'evidence:fictitious/new-provider-period', 'provider_evidence_confirmed' => true];
        $this->withHeader('Idempotency-Key', 'full-demo-verify')->postJson($furl.'/verify_renewal', $verify)->assertOk()->assertJsonPath('data.state', 'resolved');
        $this->postJson($furl.'/verify_renewal', $verify)->assertOk();
        $this->assertSame('verified', $oldCycle->fresh()->state);
        $this->assertDatabaseCount('renewal_cycles', 2);
        $this->assertSame(1, RenewalCycle::whereNotNull('active_subscription_id')->count());
        $this->assertSame(0, RenewalReminder::where('renewal_cycle_id', $oldCycle->id)->where('state', 'pending')->count());
        $this->assertSame('unknown', $service->fresh()->payment_status);
        $this->assertSame($historicBody, ContactAttempt::sole()->sent_body);
        $completion = TemplateDraft::where('template_key', 'TPL-10')->sole();
        $this->assertSame('ready', $completion->draft_status);
        app(DeliveryMaterializer::class)->materialize($org);
        $verifiedEvent = OutboxEvent::where('event_type', 'renewal.verified')->sole();
        $verifiedHeader = TelegramDelivery::where('outbox_event_id', $verifiedEvent->id)->where('notification_kind', 'internal')->sole();
        $this->assertSame('sent', app(DeliverySender::class)->send($verifiedHeader->id)->state);
        $completionBody = TelegramDelivery::where('outbox_event_id', $verifiedEvent->id)->where('notification_kind', 'client_template')->sole();
        $this->assertSame($completion->rendered_body, $completionBody->text);
        $this->assertSame('sent', app(DeliverySender::class)->send($completionBody->id)->state);
        $this->getJson($base.'/notifications')->assertOk()->assertJsonPath('data.health.state', 'fake_tested');
        $this->assertSame('CALLBACK_ALREADY_USED', TelegramUpdateReceipt::findOrFail($replay)->result_code);
        $this->assertDatabaseCount('contact_attempts', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'followup.verify_renewal']);
    }
}
