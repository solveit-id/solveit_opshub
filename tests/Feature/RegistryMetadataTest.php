<?php

namespace Tests\Feature;

use App\Application\Registry\ManagementAuthorizationService;
use App\Domain\IdentityAccess\Role;
use App\Models\Asset;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistryMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_records_hosting_metadata_without_enabling_a_connector(): void
    {
        [$organization, $owner] = $this->organizationWith(Role::Owner);
        $asset = $this->asset($organization, 'hosting_account', 'akun-contoh');

        $this->actingAs($owner)
            ->postJson($this->endpoint($organization, 'hosting-accounts'), [
                'asset_id' => $asset->id,
                'provider' => 'Contoh Hosting',
                'panel_type' => 'cpanel',
                'hostname' => 'panel.contoh.test',
                'api_endpoint' => 'https://panel.contoh.test:2083/execute',
                'account_identifier' => 'contoh-acct',
                'quota_bytes' => 1073741824,
                'available_access' => ['api_read', 'backup_read'],
                'environment_roots' => ['production' => '/home/contoh/public_html'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.panel_type', 'cpanel')
            ->assertJsonPath('data.api_endpoint', 'https://panel.contoh.test:2083/execute');

        $this->assertFalse(config('opshub.live_connectors_enabled'));
        $this->assertDatabaseCount('job_runs', 0);
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'registry.hosting_account.created']);
    }

    public function test_metadata_rejects_secret_bearing_endpoint_and_wrong_asset_kind(): void
    {
        [$organization, $owner] = $this->organizationWith(Role::Owner);
        $domain = $this->asset($organization, 'domain', 'contoh.test');
        $hosting = $this->asset($organization, 'hosting_account', 'akun-rahasia');

        $this->actingAs($owner)
            ->postJson($this->endpoint($organization, 'hosting-accounts'), [
                'asset_id' => $domain->id,
                'provider' => 'Contoh Hosting',
                'panel_type' => 'custom',
                'available_access' => ['manual_only'],
                'environment_roots' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('asset_id');

        $this->postJson($this->endpoint($organization, 'hosting-accounts'), [
            'asset_id' => $hosting->id,
            'provider' => 'Contoh Hosting',
            'panel_type' => 'custom',
            'api_endpoint' => 'https://panel.contoh.test/api?token=not-allowed',
            'available_access' => ['manual_only'],
            'environment_roots' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('api_endpoint');
    }

    public function test_subscription_keeps_unknown_expiry_null_and_requires_date_timezone_when_known(): void
    {
        [$organization, $owner] = $this->organizationWith(Role::Owner);
        $subscriptionAsset = $this->asset($organization, 'service_subscription', 'hosting-tahunan-contoh');
        $this->actingAs($owner);

        $payload = [
            'asset_id' => $subscriptionAsset->id,
            'billing_party' => 'client',
            'paying_party' => 'client',
            'action_owner' => 'client',
            'date_precision' => 'unknown',
            'source' => 'invoice_terverifikasi',
            'reminder_policy' => ['lead_days' => [60, 30, 7]],
        ];
        $this->postJson($this->endpoint($organization, 'service-subscriptions'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.date_precision', 'unknown')
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.expiry_date', null);

        $invalidAsset = $this->asset($organization, 'service_subscription', 'domain-tahunan-contoh');
        $this->postJson($this->endpoint($organization, 'service-subscriptions'), [
            ...$payload,
            'asset_id' => $invalidAsset->id,
            'date_precision' => 'date',
            'expiry_date' => '2026-12-31',
        ])->assertUnprocessable()->assertJsonValidationErrors('expiry_date');
    }

    public function test_management_authorization_is_scoped_and_expires_for_future_write_consumers(): void
    {
        [$organization, $owner] = $this->organizationWith(Role::Owner);
        $asset = $this->asset($organization, 'hosting_account', 'akun-otorisasi');
        $this->actingAs($owner);
        $account = $this->postJson($this->endpoint($organization, 'hosting-accounts'), [
            'asset_id' => $asset->id,
            'provider' => 'Contoh Hosting',
            'panel_type' => 'none',
            'available_access' => ['manual_only'],
            'environment_roots' => [],
        ])->assertCreated()->json('data');

        $authorization = $this->postJson($this->endpoint($organization, 'management-authorizations'), [
            'resource_type' => 'hosting_account',
            'resource_id' => $account['id'],
            'allowed_action_classes' => ['observe', 'backup'],
            'authorizer_label' => 'Persetujuan tertulis PIC client',
            'valid_until' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->json('data');

        $service = app(ManagementAuthorizationService::class);
        $this->assertTrue($service->allows($organization, 'hosting_account', $account['id'], 'backup'));
        $this->assertFalse($service->allows($organization, 'hosting_account', $account['id'], 'maintenance'));

        $this->patchJson($this->endpoint($organization, "management-authorizations/{$authorization['id']}"), [
            'resource_type' => 'hosting_account',
            'resource_id' => $account['id'],
            'allowed_action_classes' => ['backup'],
            'authorizer_label' => 'Persetujuan sudah kadaluarsa',
            'valid_until' => now()->subMinute()->toIso8601String(),
        ])->assertOk();

        $this->assertFalse($service->allows($organization, 'hosting_account', $account['id'], 'backup'));
    }

    /** @return array{Organization, User} */
    private function organizationWith(Role $role): array
    {
        $organization = Organization::create(['name' => fake()->company(), 'timezone' => 'Asia/Jakarta']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        Membership::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role]);

        return [$organization, $user];
    }

    private function asset(Organization $organization, string $kind, string $identity): Asset
    {
        return Asset::create([
            'organization_id' => $organization->id,
            'kind' => $kind,
            'canonical_identity' => $identity,
            'responsibility' => 'client',
            'source' => 'fixture',
        ]);
    }

    private function endpoint(Organization $organization, string $suffix): string
    {
        return "/api/v1/organizations/{$organization->id}/registry/{$suffix}";
    }
}
