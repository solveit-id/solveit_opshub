<?php

namespace Tests\Feature;

use App\Application\TelegramNotifications\TelegramConfiguration;
use App\Domain\IdentityAccess\Role;
use App\Models\Membership;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RenewalFixture;
use Tests\Support\TelegramFixture;
use Tests\TestCase;

class TelegramConfigurationTest extends TestCase
{
    use RefreshDatabase, RenewalFixture, TelegramFixture;

    public function test_only_owner_with_recent_step_up_can_configure_references(): void
    {
        [$org, $owner] = $this->renewalGraph();
        $base = '/api/v1/organizations/'.$org->id.'/telegram';
        $this->actingAs($owner)->putJson($base.'/bot', $this->botData())->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->putJson($base.'/bot', $this->botData())->assertOk()->assertJsonPath('data.enabled', false);
        $this->putJson($base.'/bot', [...$this->botData(), 'version' => 1, 'token_secret_reference' => '123456789:do-not-store-raw-token'])->assertUnprocessable();
        $operator = User::factory()->create();
        Membership::create(['organization_id' => $org->id, 'user_id' => $operator->id, 'role' => Role::Operator, 'is_active' => true]);
        $this->actingAs($operator)->getJson($base)->assertForbidden();
        $this->putJson($base.'/bot', [...$this->botData(), 'version' => 1])->assertForbidden();
        $this->assertDatabaseMissing('telegram_bots', ['token_secret_reference' => '123456789:do-not-store-raw-token']);
    }

    public function test_rotation_invalidates_identity_destinations_and_stale_configuration(): void
    {
        [$org, $owner, , , , $bot, $dest] = $this->telegramGraph();
        $c = app(TelegramConfiguration::class);
        $rotated = $c->bot($org, $owner, [...$this->botData(), 'version' => $bot->version, 'token_secret_reference' => 'env:OPSHUB_TELEGRAM_BOT_TOKEN_ROTATED']);
        $this->assertNull($rotated->identity_verified_at);
        $this->assertFalse($dest->fresh()->enabled);
        $this->assertSame(2, $rotated->identity_version);
        $url = '/api/v1/organizations/'.$org->id.'/telegram/bot';
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])->putJson($url, [...$this->botData(), 'version' => $bot->version])->assertConflict();
        $this->putJson($url, [...$this->botData(), 'version' => $rotated->version, 'enabled' => true])->assertUnprocessable();
    }

    public function test_destination_scope_chat_identity_and_explicit_test_are_guarded_and_idempotent(): void
    {
        [$org, $owner, , $project, , , $dest] = $this->telegramGraph();
        $base = '/api/v1/organizations/'.$org->id.'/telegram';
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->postJson($base.'/destinations', [...$this->destinationData([$project->id]), 'chat_id' => '-1000000002', 'scope_confirmed' => false])->assertUnprocessable();
        $this->postJson($base.'/destinations', [...$this->destinationData([999999]), 'chat_id' => '-1000000002'])->assertUnprocessable();
        $this->patchJson($base.'/destinations/'.$dest->id, [...$this->destinationData([$project->id]), 'version' => $dest->version, 'chat_id' => '-1000000002'])->assertUnprocessable();
        $this->postJson($base.'/destinations', [...$this->destinationData([$project->id]), 'chat_type' => 'private', 'chat_id' => '1000000002', 'member_user_id' => null])->assertUnprocessable();
        $url = $base.'/destinations/'.$dest->id.'/test';
        $this->withHeader('Idempotency-Key', 'explicit-test-one')->postJson($url, ['version' => $dest->version])->assertAccepted()->assertJsonPath('data.status', 'pending');
        $this->postJson($url, ['version' => $dest->version])->assertAccepted();
        $this->assertSame(1, OutboxEvent::where('event_type', 'telegram.test_requested')->count());
        $this->assertDatabaseCount('telegram_delivery_attempts', 0);
    }
}
