<?php

namespace Tests\Feature;

use App\Application\Connectors\ConnectorRegistry;
use App\Application\Connectors\ConnectorTests;
use App\Application\TelegramNotifications\DeliveryMaterializer;
use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\Cpanel\CpanelRead;
use App\Infrastructure\Connectors\Cpanel\CpanelResponse;
use App\Infrastructure\Connectors\Cpanel\CpanelTransport;
use App\Infrastructure\Testing\ScriptedCpanelTransport;
use App\Jobs\TestConnector;
use App\Models\Asset;
use App\Models\AssetUsage;
use App\Models\ConnectorAssessment;
use App\Models\Environment;
use App\Models\HostingAccount;
use App\Models\ManagementAuthorization;
use App\Models\OutboxEvent;
use App\Models\TelegramDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RenewalFixture;
use Tests\Support\TelegramFixture;
use Tests\TestCase;

class ConnectorWorkerTest extends TestCase
{
    use RefreshDatabase, RenewalFixture, TelegramFixture;

    private function fixture(): array
    {
        [$org, $owner, , $project] = $this->telegramGraph();
        $env = Environment::create(['organization_id' => $org->id, 'project_id' => $project->id, 'kind' => 'staging', 'display_name' => 'Fictitious staging']);
        $asset = Asset::create(['organization_id' => $org->id, 'kind' => 'hosting_account', 'canonical_identity' => 'fictitious-worker-account']);
        AssetUsage::create(['organization_id' => $org->id, 'asset_id' => $asset->id, 'project_id' => $project->id, 'environment_id' => $env->id, 'purpose' => 'hosting']);
        $account = HostingAccount::create(['organization_id' => $org->id, 'asset_id' => $asset->id, 'account_identifier' => 'demo']);
        $connector = app(ConnectorRegistry::class)->configure($org, $owner, new ConnectorConfig($org->id, $account->id, 'cpanel', 'https://panel.example:2083', 'demo', 'env:OPSHUB_CONNECTOR_TEST_TOKEN'));
        ManagementAuthorization::create(['organization_id' => $org->id, 'resource_type' => 'hosting_account', 'resource_id' => $account->id, 'allowed_action_classes' => ['observe'], 'valid_until' => now('UTC')->addDay()]);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

        return [$org->fresh(), $owner, $connector];
    }

    private function ok(array $data = []): CpanelResponse
    {
        return new CpanelResponse(200, ['result' => ['status' => 1, 'data' => $data]]);
    }

    public function test_http_test_is_durable_idempotent_worker_only_and_auth_failure_has_internal_alert(): void
    {
        [$org, , $connector] = $this->fixture();
        $transport = new ScriptedCpanelTransport([new CpanelResponse(401, ['private' => 'never-reflect-secret'])]);
        app()->instance(CpanelTransport::class, $transport);
        $url = '/api/v1/organizations/'.$org->id.'/connectors/'.$connector->id.'/test';
        $first = $this->withHeader('Idempotency-Key', 'test-connection-1')->postJson($url, ['version' => 1])->assertAccepted();
        $this->postJson($url, ['version' => 1])->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame([], $transport->calls);
        $this->postJson($url, ['version' => 2])->assertConflict();
        $this->withHeader('Idempotency-Key', 'different-test-key')->postJson($url, ['version' => 1])->assertConflict();
        (new TestConnector($first->json('data.id')))->handle(app(ConnectorTests::class));
        (new TestConnector($first->json('data.id')))->handle(app(ConnectorTests::class));
        $this->assertCount(1, $transport->calls);
        $this->assertSame('auth_failed', $connector->fresh()->state);
        $this->assertTrue($connector->fresh()->writes_paused);
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseHas('connector_test_runs', ['id' => $first->json('data.id'), 'state' => 'failed', 'reason_code' => 'AUTH_FAILED', 'attempts' => 1]);
        $this->assertSame(1, OutboxEvent::where('event_type', 'connector.failed')->count());
        app(DeliveryMaterializer::class)->materialize($org);
        $alert = TelegramDelivery::whereIn('outbox_event_id', OutboxEvent::where('event_type', 'connector.failed')->select('id'))->sole();
        $this->assertStringContainsString('uptime website tidak disimpulkan', $alert->text);
        $this->assertStringNotContainsString('never-reflect-secret', $alert->text);
        $this->assertStringNotContainsString('OPSHUB_CONNECTOR_TEST_TOKEN', $alert->text);
        $this->assertTrue($alert->fake);
        $this->assertDatabaseCount('telegram_delivery_attempts', 0);
    }

    public function test_reference_switch_occurs_only_after_successful_candidate_and_old_revocation_is_manual(): void
    {
        [$org, $owner, $connector] = $this->fixture();
        $service = app(ConnectorTests::class);
        $first = $service->enqueue($org, $owner, $connector, 1, 'failed-rotation-key', 'env:OPSHUB_CONNECTOR_NEW_TOKEN');
        app()->instance(CpanelTransport::class, new ScriptedCpanelTransport([new CpanelResponse(403)]));
        $service->execute($first->id);
        $this->assertSame('env:OPSHUB_CONNECTOR_TEST_TOKEN', $connector->fresh()->configuration['secret_reference']);
        $this->assertSame(1, $connector->fresh()->version);
        $this->assertDatabaseCount('connector_assessments', 0);
        $this->assertSame('failed', $first->fresh()->state);
        $this->travel(31)->seconds();
        $second = $service->enqueue($org, $owner, $connector, 1, 'valid-rotation-key', 'env:OPSHUB_CONNECTOR_NEW_TOKEN');
        app()->instance(CpanelTransport::class, new ScriptedCpanelTransport([$this->ok(['backup' => 1]), $this->ok(['megabyte_limit' => 0, 'megabytes_used' => 0])]));
        $service->execute($second->id);
        $this->assertSame('env:OPSHUB_CONNECTOR_NEW_TOKEN', $connector->fresh()->configuration['secret_reference']);
        $this->assertSame(2, $connector->fresh()->version);
        $this->assertSame('completed', $second->fresh()->state);
        $this->assertSame('env:OPSHUB_CONNECTOR_TEST_TOKEN', $second->fresh()->revoke_reference);
        $this->assertArrayNotHasKey('revoke_reference', $second->fresh()->toArray());
        $this->assertDatabaseHas('audit_events', ['action' => 'connector.reference.rotated']);
        $this->assertSame('live_unverified', $connector->fresh()->validation_state);
        $this->assertTrue($connector->fresh()->writes_paused);
        $this->assertSame(0, ConnectorAssessment::where('configuration_version', 1)->count());
        $this->assertTrue(ConnectorAssessment::where('configuration_version', 2)->get()->every(fn ($r) => $r->source === 'fake'));
        $this->assertSame('unknown', ConnectorAssessment::where('capability', 'full_backup_trigger')->sole()->status);
    }

    public function test_worker_rechecks_revoked_authorization_and_stale_configuration_before_provider_read(): void
    {
        [$org, $owner, $connector] = $this->fixture();
        $service = app(ConnectorTests::class);
        $transport = new ScriptedCpanelTransport([$this->ok()]);
        app()->instance(CpanelTransport::class, $transport);
        $run = $service->enqueue($org, $owner, $connector, 1, 'revoked-scope-key');
        ManagementAuthorization::where('resource_id', $connector->hosting_account_id)->update(['valid_until' => now()->subSecond()]);
        $service->execute($run->id);
        $this->assertSame('cancelled', $run->fresh()->state);
        $this->assertSame([], $transport->calls);
        $this->travel(31)->seconds();
        ManagementAuthorization::where('resource_id', $connector->hosting_account_id)->update(['valid_until' => now()->addDay()]);
        $run2 = $service->enqueue($org, $owner, $connector, 1, 'stale-version-key');
        $connector->update(['version' => 2]);
        $service->execute($run2->id);
        $this->assertSame('cancelled', $run2->fresh()->state);
        $this->assertSame([], $transport->calls);
    }

    public function test_late_worker_is_fenced_after_configuration_changes_and_candidate_read_denial_does_not_switch(): void
    {
        [$org, $owner, $connector] = $this->fixture();
        $service = app(ConnectorTests::class);
        $run = $service->enqueue($org, $owner, $connector, 1, 'midflight-test-key');
        $transport = new class($connector) implements CpanelTransport
        {
            public function __construct(private $connector) {}

            public function read(ConnectorConfig $config, CpanelRead $operation): CpanelResponse
            {
                $this->connector->update(['version' => 2]);

                return new CpanelResponse(200, ['result' => ['status' => 1, 'data' => []]], fake: true);
            }
        };
        app()->instance(CpanelTransport::class, $transport);
        $service->execute($run->id);
        $this->assertSame('cancelled', $run->fresh()->state);
        $this->assertDatabaseCount('connector_assessments', 0);
        $this->travel(31)->seconds();
        $candidate = $service->enqueue($org, $owner, $connector->fresh(), 2, 'quota-denied-test-key', 'env:OPSHUB_CONNECTOR_NEW_TOKEN');
        app()->instance(CpanelTransport::class, new ScriptedCpanelTransport([$this->ok(), new CpanelResponse(403)]));
        $service->execute($candidate->id);
        $this->assertSame('failed', $candidate->fresh()->state);
        $this->assertSame('env:OPSHUB_CONNECTOR_TEST_TOKEN', $connector->fresh()->configuration['secret_reference']);
        $this->assertDatabaseCount('connector_assessments', 0);
    }

    public function test_native_gate_false_and_queue_transaction_rollback_never_contact_provider(): void
    {
        [$org, $owner, $connector] = $this->fixture();
        $service = app(ConnectorTests::class);
        try {
            DB::transaction(function () use ($org, $owner, $connector, $service): void {
                $service->enqueue($org, $owner, $connector, 1, 'rolled-back-test-key');
                throw new \LogicException('rollback');
            });
        } catch (\LogicException) {
        }
        $this->assertDatabaseCount('connector_test_runs', 0);
        $this->assertDatabaseCount('jobs', 0);
        config(['opshub.live_connectors_enabled' => false]);
        $run = $service->enqueue($org, $owner, $connector, 1, 'native-gate-false-key');
        $service->execute($run->id);
        $this->assertSame('failed', $run->fresh()->state);
        $this->assertSame('NOT_CONFIGURED', $run->fresh()->reason_code);
        $this->assertTrue($connector->fresh()->writes_paused);
    }

    public function test_expired_read_lease_requires_explicit_replay_and_stops_at_three_attempts(): void
    {
        [$org, $owner, $connector] = $this->fixture();
        $service = app(ConnectorTests::class);
        $run = $service->enqueue($org, $owner, $connector, 1, 'recover-read-lease-key');
        $run->update(['state' => 'running', 'attempts' => 1, 'lease_owner' => 'old-worker', 'leased_until' => now()->subSecond()]);
        $service->enqueue($org, $owner, $connector, 1, 'recover-read-lease-key');
        $this->assertSame('queued', $run->fresh()->state);
        $this->assertDatabaseCount('jobs', 2);
        $run->refresh()->update(['state' => 'running', 'attempts' => 3, 'lease_owner' => 'third-worker', 'leased_until' => now()->subSecond()]);
        $service->enqueue($org, $owner, $connector, 1, 'recover-read-lease-key');
        $this->assertSame('failed', $run->fresh()->state);
        $this->assertSame('NETWORK_TIMEOUT', $run->fresh()->reason_code);
        $this->assertDatabaseCount('jobs', 2);
        $this->assertNull($run->fresh()->active_connector_id);
        $this->assertTrue($connector->fresh()->writes_paused);
    }
}
