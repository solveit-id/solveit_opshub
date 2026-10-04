<?php

namespace Tests\Feature;

use App\Application\RenewalFollowups\RenewalScheduler;
use App\Application\TelegramNotifications\DeliveryMaterializer;
use App\Application\TelegramNotifications\DeliverySender;
use App\Application\TelegramNotifications\TelegramBindingService;
use App\Application\TelegramNotifications\TelegramUpdateProcessor;
use App\Domain\IdentityAccess\Role;
use App\Infrastructure\Telegram\TelegramSecretResolver;
use App\Models\ClientFollowup;
use App\Models\Membership;
use App\Models\TelegramBinding;
use App\Models\TelegramBindingIntent;
use App\Models\TelegramCallbackReference;
use App\Models\TelegramDelivery;
use App\Models\TelegramUpdateReceipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RenewalFixture;
use Tests\Support\TelegramFixture;
use Tests\TestCase;

class TelegramReceiverTest extends TestCase
{
    use RefreshDatabase, RenewalFixture, TelegramFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-04T03:00:00Z'));
        app()->instance(TelegramSecretResolver::class, new class implements TelegramSecretResolver
        {
            public function resolve(string $reference): string
            {
                return 'fictitious-webhook-secret';
            }
        });
    }

    private function message(int $update, int $user, string $text, ?int $group = null): array
    {
        return ['update_id' => $update, 'message' => ['message_id' => $update, 'from' => ['id' => $user, 'is_bot' => false, 'username' => 'ignored_username'], 'chat' => ['id' => $group ?? $user, 'type' => $group ? 'supergroup' : 'private'], 'text' => $text]];
    }

    private function receive($bot, array $payload): TelegramUpdateReceipt
    {
        $response = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'fictitious-webhook-secret')->postJson('/api/telegram/webhook/'.$bot->id, $payload)->assertOk();

        return TelegramUpdateReceipt::findOrFail($response->json('receipt_id'));
    }

    private function bound($org, $owner, $bot, int $id = 100001): TelegramBinding
    {
        $intent = app(TelegramBindingService::class)->start($org, $owner);
        $receipt = $this->receive($bot, $this->message(1, $id, $intent['command']));
        app(TelegramUpdateProcessor::class)->process($receipt->id);

        return app(TelegramBindingService::class)->confirm($org, $owner, TelegramBindingIntent::findOrFail($intent['intent_id']), (string) $id);
    }

    private function callbackPayload(int $update, int $user, string $reference, int $chat): array
    {
        return ['update_id' => $update, 'callback_query' => ['id' => 'fixture-query-'.$update, 'from' => ['id' => $user, 'is_bot' => false], 'message' => ['message_id' => 1, 'chat' => ['id' => $chat, 'type' => 'supergroup']], 'data' => $reference]];
    }

    public function test_forgery_malformed_and_duplicate_updates_are_durable_before_one_async_job(): void
    {
        [, , , , , $bot] = $this->telegramGraph();
        $url = '/api/telegram/webhook/'.$bot->id;
        $this->postJson($url, $this->message(10, 100001, '/help'))->assertForbidden();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'fictitious-webhook-secret')->postJson($url, ['update_id' => '10'])->assertUnprocessable();
        $this->assertDatabaseCount('telegram_update_receipts', 0);
        $r = $this->receive($bot, $this->message(10, 100001, '/help'));
        $this->assertSame('pending', $r->status);
        $this->assertDatabaseCount('jobs', 1);
        $duplicate = $this->receive($bot, $this->message(10, 100001, '/today'));
        $this->assertSame($r->id, $duplicate->id);
        $this->assertDatabaseCount('jobs', 1);
        app(TelegramUpdateProcessor::class)->process($r->id);
        app(TelegramUpdateProcessor::class)->process($r->id);
        $this->assertSame('HELP', $r->fresh()->result_code);
        $this->assertSame(1, TelegramDelivery::count());
        $this->assertStringNotContainsString('ignored_username', $r->encrypted_payload);
        $this->assertArrayNotHasKey('encrypted_payload', $r->toArray());
    }

    public function test_private_candidate_requires_correct_dashboard_user_and_id_and_token_cannot_be_reused(): void
    {
        [$org, $owner, , , , $bot] = $this->telegramGraph();
        $url = '/api/v1/organizations/'.$org->id.'/telegram-binding';
        $this->actingAs($owner)->postJson($url)->assertForbidden();
        $response = $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson($url)->assertCreated();
        $intent = TelegramBindingIntent::findOrFail($response->json('data.intent_id'));
        $command = $response->json('data.command');
        $group = $this->receive($bot, $this->message(10, 100001, $command, -1000000001));
        app(TelegramUpdateProcessor::class)->process($group->id);
        $this->assertSame('pending', $intent->fresh()->state);
        $this->assertSame(0, TelegramBinding::count());
        $r = $this->receive($bot, $this->message(11, 100001, $command));
        app(TelegramUpdateProcessor::class)->process($r->id);
        $this->assertSame('candidate', $intent->fresh()->state);
        $this->assertSame(0, TelegramBinding::count());
        $other = User::factory()->create();
        Membership::create(['organization_id' => $org->id, 'user_id' => $other->id, 'role' => Role::Operations, 'is_active' => true]);
        $confirm = $url.'/'.$intent->id.'/confirm';
        $this->actingAs($other)->postJson($confirm, ['telegram_user_id' => '100001', 'private_identity_confirmed' => true])->assertNotFound();
        $this->actingAs($owner)->postJson($confirm, ['telegram_user_id' => '100002', 'private_identity_confirmed' => true])->assertConflict();
        $this->postJson($confirm, ['telegram_user_id' => '100001', 'private_identity_confirmed' => false])->assertUnprocessable();
        $this->postJson($confirm, ['telegram_user_id' => '100001', 'private_identity_confirmed' => true])->assertOk();
        $this->postJson($confirm, ['telegram_user_id' => '100001', 'private_identity_confirmed' => true])->assertConflict();
        $replay = $this->receive($bot, $this->message(12, 100002, $command));
        app(TelegramUpdateProcessor::class)->process($replay->id);
        $this->assertSame('BINDING_DENIED', $replay->fresh()->result_code);
        $this->assertSame('100001', TelegramBinding::first()->telegram_user_id);
        $this->assertStringNotContainsString(substr($command, 7), json_encode($intent->fresh()->toArray()));
        $this->get('/organizations/'.$org->id.'/telegram-binding')->assertInertia(fn (Assert $p) => $p->component('Telegram/Binding')->where('binding.enabled', true));
        $this->deleteJson($url)->assertOk();
        $this->assertNull(app(TelegramBindingService::class)->current($bot, '100001'));
    }

    public function test_expired_or_stolen_intent_never_finalizes_without_dashboard_confirmation(): void
    {
        [$org, $owner, , , , $bot] = $this->telegramGraph();
        $intent = app(TelegramBindingService::class)->start($org, $owner);
        $r = $this->receive($bot, $this->message(10, 100002, $intent['command']));
        app(TelegramUpdateProcessor::class)->process($r->id);
        $this->assertSame(0, TelegramBinding::count());
        $this->travel(10)->minutes();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson('/api/v1/organizations/'.$org->id.'/telegram-binding/'.$intent['intent_id'].'/confirm', ['telegram_user_id' => '100002', 'private_identity_confirmed' => true])->assertConflict();
        $this->assertSame(0, TelegramBinding::count());
        $r = $this->receive($bot, $this->message(11, 100001, $intent['command']));
        app(TelegramUpdateProcessor::class)->process($r->id);
        $this->assertSame('BINDING_DENIED', $r->fresh()->result_code);
    }

    public function test_callbacks_are_opaque_scoped_current_permission_checked_and_replay_safe(): void
    {
        [$org, $owner, , , , $bot, $dest] = $this->telegramGraph();
        $binding = $this->bound($org, $owner, $bot);
        app(RenewalScheduler::class)->tick($org);
        app(DeliveryMaterializer::class)->materialize($org);
        $f = ClientFollowup::firstOrFail();
        $initialVersion = $f->version;
        $header = TelegramDelivery::where('notification_kind', 'internal')->firstOrFail();
        $reference = collect($header->reply_markup['inline_keyboard'][1])->firstWhere('text', 'Ambil tugas')['callback_data'];
        $this->assertSame(48, strlen($reference));
        $this->assertDatabaseHas('telegram_callback_references', ['reference_hash' => hash('sha256', $reference), 'action' => 'claim']);
        $denied = $this->receive($bot, $this->callbackPayload(10, 100002, $reference, (int) $dest->chat_id));
        app(TelegramUpdateProcessor::class)->process($denied->id);
        $this->assertSame('ACTION_DENIED', $denied->fresh()->result_code);
        $this->assertSame($initialVersion, $f->fresh()->version);
        $crossChat = $this->receive($bot, $this->callbackPayload(11, 100001, $reference, -1000000999));
        app(TelegramUpdateProcessor::class)->process($crossChat->id);
        $this->assertSame('ACTION_DENIED', $crossChat->fresh()->result_code);
        $r = $this->receive($bot, $this->callbackPayload(12, 100001, $reference, (int) $dest->chat_id));
        app(TelegramUpdateProcessor::class)->process($r->id);
        $this->assertSame('CALLBACK_APPLIED', $r->fresh()->result_code);
        $this->assertSame($owner->id, $f->fresh()->assignee_user_id);
        $replay = $this->receive($bot, $this->callbackPayload(13, 100001, $reference, (int) $dest->chat_id));
        app(TelegramUpdateProcessor::class)->process($replay->id);
        $this->assertSame('CALLBACK_ALREADY_USED', $replay->fresh()->result_code);
        $this->assertSame($initialVersion + 1, $f->fresh()->version);
        Membership::where('organization_id', $org->id)->where('user_id', $owner->id)->update(['is_active' => false]);
        $revoked = $this->receive($bot, $this->callbackPayload(14, 100001, $reference, (int) $dest->chat_id));
        app(TelegramUpdateProcessor::class)->process($revoked->id);
        $this->assertSame('ACTION_DENIED', $revoked->fresh()->result_code);
        $this->assertSame($initialVersion + 1, $f->fresh()->version);
        $this->assertDatabaseCount('contact_attempts', 0);
    }

    public function test_old_template_callback_and_group_command_reply_only_with_current_private_scoped_data(): void
    {
        [$org, $owner, $service, , , $bot, $dest, $fake] = $this->telegramGraph();
        $this->bound($org, $owner, $bot);
        app(RenewalScheduler::class)->tick($org);
        app(DeliveryMaterializer::class)->materialize($org);
        $f = ClientFollowup::firstOrFail();
        $header = TelegramDelivery::where('notification_kind', 'internal')->firstOrFail();
        $reference = collect($header->reply_markup['inline_keyboard'][1])->firstWhere('text', 'Template terbaru')['callback_data'];
        $service->update(['service_name' => 'Hosting CURRENT name', 'version' => $service->version + 1]);
        $r = $this->receive($bot, $this->callbackPayload(10, 100001, $reference, (int) $dest->chat_id));
        app(TelegramUpdateProcessor::class)->process($r->id);
        $reply = TelegramDelivery::where('receipt_id', $r->id)->firstOrFail();
        $this->assertStringContainsString('Hosting CURRENT name', $reply->text);
        $this->assertStringNotContainsString('Event:', $reply->text);
        $this->assertSame('sent', app(DeliverySender::class)->send($reply->id)->state);
        $this->assertSame('100001', end($fake->calls)['payload']['chat_id']);
        $command = $this->receive($bot, $this->message(11, 100001, '/today', (int) $dest->chat_id));
        app(TelegramUpdateProcessor::class)->process($command->id);
        $d = TelegramDelivery::where('receipt_id', $command->id)->firstOrFail();
        $this->assertSame('private:100001', $d->recipient_reference);
        $this->assertStringContainsString('Hosting CURRENT name', $d->text);
        TelegramBinding::first()->update(['enabled' => false]);
        $this->assertSame('cancelled', app(DeliverySender::class)->send($d->id, CarbonImmutable::now('UTC')->addSecond())->state);
        $denied = $this->receive($bot, $this->message(12, 100002, '/template '.$f->id));
        app(TelegramUpdateProcessor::class)->process($denied->id);
        $this->assertSame('ACTION_DENIED', $denied->fresh()->result_code);
        $this->assertSame(0, TelegramDelivery::where('receipt_id', $denied->id)->count());
    }

    public function test_viewer_disabled_identity_stale_action_and_expired_reference_are_denied(): void
    {
        [$org, $owner, , , , $bot, $dest] = $this->telegramGraph();
        $this->bound($org, $owner, $bot);
        app(RenewalScheduler::class)->tick($org);
        app(DeliveryMaterializer::class)->materialize($org);
        $header = TelegramDelivery::where('notification_kind', 'internal')->firstOrFail();
        $reference = collect($header->reply_markup['inline_keyboard'][1])->firstWhere('text', 'Acknowledge')['callback_data'];
        $f = ClientFollowup::firstOrFail();
        $f->update(['version' => $f->version + 1]);
        $r = $this->receive($bot, $this->callbackPayload(10, 100001, $reference, (int) $dest->chat_id));
        app(TelegramUpdateProcessor::class)->process($r->id);
        $this->assertSame('ACTION_DENIED', $r->fresh()->result_code);
        Membership::where('organization_id', $org->id)->where('user_id', $owner->id)->update(['role' => Role::Viewer->value]);
        $r = $this->receive($bot, $this->callbackPayload(11, 100001, $reference, (int) $dest->chat_id));
        app(TelegramUpdateProcessor::class)->process($r->id);
        $this->assertSame('ACTION_DENIED', $r->fresh()->result_code);
        Membership::where('organization_id', $org->id)->where('user_id', $owner->id)->update(['role' => Role::Owner->value]);
        TelegramCallbackReference::where('reference_hash', hash('sha256', $reference))->update(['expires_at' => now('UTC')]);
        $r = $this->receive($bot, $this->callbackPayload(12, 100001, $reference, (int) $dest->chat_id));
        app(TelegramUpdateProcessor::class)->process($r->id);
        $this->assertSame('ACTION_DENIED', $r->fresh()->result_code);
        $pending = $this->receive($bot, $this->message(13, 100001, '/today'));
        $bot->update(['identity_version' => $bot->identity_version + 1]);
        app(TelegramUpdateProcessor::class)->process($pending->id);
        $this->assertSame('RECEIVER_DISABLED', $pending->fresh()->result_code);
        $this->assertNull(app(TelegramBindingService::class)->current($bot->fresh(), '100001'));
    }

    public function test_owner_webhook_setup_is_explicit_https_and_secret_never_in_business_receipt(): void
    {
        [$org, $owner, , , , $bot, , $fake] = $this->telegramGraph();
        $base = '/api/v1/organizations/'.$org->id.'/telegram/webhook';
        $this->actingAs($owner)->postJson($base, ['version' => $bot->version])->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson($base, ['version' => $bot->version])->assertUnprocessable();
        config(['app.url' => 'https://dashboard.example']);
        $this->postJson($base, ['version' => $bot->version])->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.fake', true);
        $this->assertSame('setWebhook', end($fake->calls)['method']);
        $this->assertFalse(end($fake->calls)['payload']['drop_pending_updates']);
        $this->assertStringNotContainsString('fictitious-webhook-secret', json_encode($bot->fresh()->toArray()));
    }

    public function test_current_scope_and_disabled_user_are_rechecked_for_commands_and_callbacks(): void
    {
        [$org, $owner, , $project, , $bot, $dest] = $this->telegramGraph();
        $this->bound($org, $owner, $bot);
        app(RenewalScheduler::class)->tick($org);
        app(DeliveryMaterializer::class)->materialize($org);
        $header = TelegramDelivery::where('notification_kind', 'internal')->firstOrFail();
        $reference = collect($header->reply_markup['inline_keyboard'][1])->firstWhere('text', 'Ambil tugas')['callback_data'];
        Membership::where('organization_id', $org->id)->where('user_id', $owner->id)->update(['role' => Role::Operations->value]);
        $project->update(['internal_pic_user_id' => null]);
        $r = $this->receive($bot, $this->callbackPayload(10, 100001, $reference, (int) $dest->chat_id));
        app(TelegramUpdateProcessor::class)->process($r->id);
        $this->assertSame('ACTION_DENIED', $r->fresh()->result_code);
        $this->assertNull(ClientFollowup::first()->assignee_user_id);
        $r = $this->receive($bot, $this->message(11, 100001, '/today'));
        app(TelegramUpdateProcessor::class)->process($r->id);
        $reply = TelegramDelivery::where('receipt_id', $r->id)->firstOrFail();
        $this->assertStringContainsString('scope: 0', $reply->text);
        $this->assertStringNotContainsString('Hosting Fixture', $reply->text);
        $owner->update(['is_active' => false]);
        $r = $this->receive($bot, $this->callbackPayload(12, 100001, $reference, (int) $dest->chat_id));
        app(TelegramUpdateProcessor::class)->process($r->id);
        $this->assertSame('ACTION_DENIED', $r->fresh()->result_code);
        $this->assertSame('cancelled', app(DeliverySender::class)->send($reply->id)->state);
    }
}
