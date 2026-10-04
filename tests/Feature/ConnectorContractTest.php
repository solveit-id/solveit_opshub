<?php

namespace Tests\Feature;

use App\Application\Connectors\CapabilityRecorder;
use App\Application\Connectors\ConnectorRegistry;
use App\Domain\IdentityAccess\Role;
use App\Infrastructure\Connectors\BackupRequest;
use App\Infrastructure\Connectors\Capability;
use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorResult;
use App\Infrastructure\Connectors\Fakes\ScriptedConnectorAdapter;
use App\Models\Asset;
use App\Models\AssetUsage;
use App\Models\HostingAccount;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\MonitoringFixture;
use Tests\TestCase;

class ConnectorContractTest extends TestCase
{
    use MonitoringFixture, RefreshDatabase;

    private function connectorGraph(): array
    {
        [$org, $project, $monitor, $env] = $this->graph();
        $owner = User::factory()->create();
        Membership::create(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => Role::Owner, 'is_active' => true]);
        $asset = Asset::create(['organization_id' => $org->id, 'kind' => 'hosting_account', 'canonical_identity' => 'fictitious-account']);
        AssetUsage::create(['organization_id' => $org->id, 'asset_id' => $asset->id, 'project_id' => $project->id, 'environment_id' => $env->id, 'purpose' => 'hosting']);
        $account = HostingAccount::create(['organization_id' => $org->id, 'asset_id' => $asset->id, 'account_identifier' => 'demo']);
        $config = new ConnectorConfig($org->id, $account->id, 'cpanel', 'https://panel.example', 'demo', 'env:OPSHUB_CONNECTOR_TEST_TOKEN');
        $connector = app(ConnectorRegistry::class)->configure($org, $owner, $config);

        return [$org, $owner, $connector, $config, $project, $monitor, $account];
    }

    public function test_normalized_result_discards_raw_errors_and_enforces_typed_non_success(): void
    {
        $result = new ConnectorResult('unsupported', 'account_disk_read', 'PROVIDER_FEATURE_DISABLED',
            'cpanel demo:secret-token https://x?password=secret', true, CarbonImmutable::parse('2026-10-04 12:00', 'Asia/Jakarta'),
            ['quota_bytes' => null, 'used_bytes' => 12, 'token' => 'secret-token', 'body' => 'raw private response', 'file_count' => -1, 'bytes' => '123']);
        $this->assertFalse($result->successful());
        $this->assertSame('2026-10-04T05:00:00+00:00', $result->observedAt->toIso8601String());
        $this->assertSame(['quota_bytes' => null, 'used_bytes' => 12], $result->evidence);
        $this->assertStringNotContainsString('secret-token', json_encode($result));
        $this->assertSame('fake', $result->jsonSerialize()['source']);
        $this->expectException(InvalidArgumentException::class);
        new ConnectorResult('supported', 'full_backup_trigger', 'UNSUPPORTED_CAPABILITY');
    }

    public function test_config_rejects_secret_urls_shell_paths_and_unpinned_sftp(): void
    {
        foreach (['https://user:pass@panel.example', 'https://panel.example?token=secret', 'http://panel.example', 'https://panel.example/execute', "https://panel.example\n"] as $endpoint) {
            try {
                new ConnectorConfig(1, 1, 'cpanel', $endpoint, 'demo', 'env:OPSHUB_CONNECTOR_TEST_TOKEN');
                $this->fail('Ambiguous or secret endpoint accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['/home/demo/../private', '/', '/home/demo/./data', '/home/demo\\private'] as $root) {
            try {
                new ConnectorConfig(1, 1, 'sftp', 'sftp://sftp.example', 'demo', 'env:OPSHUB_CONNECTOR_TEST_KEY', [$root], 'SHA256:'.str_repeat('A', 43));
                $this->fail('Ambiguous allowed root accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new ConnectorConfig(1, 1, 'sftp', 'sftp://sftp.example', 'demo', 'raw-secret', ['/home/demo']);
    }

    public function test_uniform_fake_contract_has_provenance_and_read_only_discovery(): void
    {
        [, , , $config] = $this->connectorGraph();
        $adapter = new ScriptedConnectorAdapter;
        $this->assertFalse($adapter->validateConfig($config)->successful());
        $results = $adapter->discoverCapabilities($config);
        $this->assertCount(count(Capability::cases()) - 1, $results);
        foreach ($results as $result) {
            $this->assertTrue($result->fake);
            $this->assertFalse($result->successful());
        }
        $this->assertNotContains('backup', array_column($adapter->calls, 0));
        $request = new BackupRequest('opshub-'.Str::uuid(), Capability::FullBackupTrigger);
        $this->assertFalse($adapter->requestBackup($config, $request)->successful());
        $this->assertFalse($adapter->reconcile($config, $request)->successful());
        $this->assertFalse($adapter->readObservation($config, Capability::AccountDiskRead)->successful());
    }

    public function test_assessments_are_scoped_versioned_append_only_and_never_enable_writes(): void
    {
        [$org, $owner, $connector, $config, , $monitor] = $this->connectorGraph();
        $recorder = app(CapabilityRecorder::class);
        $recorder->record($connector, 1, 'validate', new ConnectorResult('supported', 'connection', null, fake: true));
        $recorder->record($connector, 1, 'discover', new ConnectorResult('unsupported', 'account_disk_read', 'UNSUPPORTED_CAPABILITY', fake: true, evidence: ['quota_bytes' => null]));
        $this->assertSame('connected', $connector->fresh()->state);
        $this->assertTrue($connector->fresh()->writes_paused);
        $this->assertSame('live_unverified', $connector->fresh()->validation_state);
        $this->assertNull($monitor->fresh()->last_completed_at);
        $assessment = $recorder->record($connector, 1, 'read', new ConnectorResult('permission_denied', 'account_disk_read', 'AUTH_FAILED', fake: true));
        $this->assertSame('auth_failed', $connector->fresh()->state);
        $this->assertDatabaseHas('outbox_events', ['organization_id' => $org->id, 'event_type' => 'connector.failed']);
        $this->assertDatabaseCount('observations', 0);
        $this->assertSame($assessment->id, collect($recorder->current($connector->fresh()))->firstWhere('capability', 'account_disk_read')['id']);
        $this->assertSame('UTC', $assessment->fresh()->observed_at->timezoneName);
        $next = app(ConnectorRegistry::class)->configure($org, $owner, $config, 1);
        $this->assertSame([], $recorder->current($next));
        $this->assertDatabaseCount('connector_assessments', 3);
        try {
            $recorder->record($next, 1, 'read', new ConnectorResult('supported', 'account_disk_read', null, fake: true));
            $this->fail('Stale connector result accepted.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->expectException(LogicException::class);
        $assessment->update(['status' => 'supported']);
    }

    public function test_api_requires_step_up_explicit_permission_account_scope_and_stale_version_guard(): void
    {
        [$org, $owner, $connector, , $project, , $account] = $this->connectorGraph();
        $url = '/api/v1/organizations/'.$org->id.'/connectors';
        $body = ['hosting_account_id' => $account->id, 'kind' => 'cpanel', 'endpoint' => 'https://panel.example', 'account_identifier' => 'demo', 'secret_reference' => 'env:OPSHUB_CONNECTOR_TEST_TOKEN', 'version' => 1];
        $this->actingAs($owner)->postJson($url, $body)->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson($url, $body)->assertOk()->assertJsonMissingPath('data.configuration');
        $this->postJson($url, $body)->assertConflict();
        $this->postJson($url, [...$body, 'version' => 2, 'secret_reference' => 'raw-token-secret'])->assertUnprocessable();
        $this->postJson($url, [...$body, 'version' => 2, 'secret_reference' => 'env:OPSHUB_CONNECTOR_NEW_TOKEN'])->assertUnprocessable();
        $foreign = Organization::create(['name' => 'Other', 'timezone' => 'UTC']);
        $this->getJson('/api/v1/organizations/'.$foreign->id.'/connectors/'.$connector->id)->assertForbidden();
        $viewer = User::factory()->create();
        Membership::create(['organization_id' => $org->id, 'user_id' => $viewer->id, 'role' => Role::Viewer, 'is_active' => true]);
        $this->actingAs($viewer)->getJson($url.'/'.$connector->id)->assertNotFound();
        $this->postJson($url, [...$body, 'version' => 2])->assertForbidden();
        $operator = User::factory()->create();
        $member = Membership::create(['organization_id' => $org->id, 'user_id' => $operator->id, 'role' => Role::Operator, 'is_active' => true]);
        $this->actingAs($operator)->postJson($url, [...$body, 'version' => 2])->assertForbidden();
        $member->update(['extra_permissions' => ['connector.manage']]);
        $this->postJson($url, [...$body, 'version' => 2])->assertNotFound();
        $project->update(['internal_pic_user_id' => $operator->id]);
        $this->postJson($url, [...$body, 'version' => 2])->assertOk();
        $this->assertDatabaseCount('connectors', 1);
    }

    public function test_late_evidence_cannot_reverse_auth_failure_and_fake_is_refused_outside_testing(): void
    {
        [, , $connector] = $this->connectorGraph();
        $recorder = app(CapabilityRecorder::class);
        $recorder->record($connector, 1, 'validate', new ConnectorResult('permission_denied', 'connection', 'AUTH_FAILED', fake: true));
        $recorder->record($connector, 1, 'validate', new ConnectorResult('supported', 'connection', null, fake: true, observedAt: CarbonImmutable::now('UTC')->subMinute()));
        $this->assertSame('auth_failed', $connector->fresh()->state);
        $this->app->instance('env', 'production');
        $this->expectException(LogicException::class);
        $recorder->record($connector, 1, 'validate', new ConnectorResult('supported', 'connection', null, fake: true));
    }
}
