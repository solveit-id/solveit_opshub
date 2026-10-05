<?php

namespace Tests\Feature;

use App\Application\Backups\BackupPolicies;
use App\Application\Backups\BackupPolicySettings;
use App\Application\Backups\BackupRuns;
use App\Application\Connectors\CapabilityRecorder;
use App\Application\Connectors\ConnectorRegistry;
use App\Domain\IdentityAccess\Role;
use App\Infrastructure\Backup\BackupCapacity;
use App\Infrastructure\Backup\CapacitySnapshot;
use App\Infrastructure\Backup\UnavailableBackupCapacity;
use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorResult;
use App\Infrastructure\Testing\FixedBackupCapacity;
use App\Jobs\PreflightBackup;
use App\Models\Asset;
use App\Models\AssetUsage;
use App\Models\Environment;
use App\Models\HostingAccount;
use App\Models\ManagementAuthorization;
use App\Models\Membership;
use App\Models\OutboxEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RenewalFixture;
use Tests\Support\TelegramFixture;
use Tests\TestCase;

class BackupPreflightTest extends TestCase
{
    use RefreshDatabase, RenewalFixture, TelegramFixture;

    private function fixture(string $kind = 'sftp'): array
    {
        [$org, $owner, , $project] = $this->telegramGraph();
        $org->refresh();
        $operator = User::factory()->create();
        Membership::create(['organization_id' => $org->id, 'user_id' => $operator->id, 'role' => Role::Operator, 'is_active' => true]);
        $env = Environment::create(['organization_id' => $org->id, 'project_id' => $project->id, 'kind' => 'staging', 'display_name' => 'Fictitious backup']);
        $asset = Asset::create(['organization_id' => $org->id, 'kind' => 'hosting_account', 'canonical_identity' => 'fictitious-backup-account']);
        AssetUsage::create(['organization_id' => $org->id, 'asset_id' => $asset->id, 'project_id' => $project->id, 'environment_id' => $env->id, 'purpose' => 'hosting']);
        $account = HostingAccount::create(['organization_id' => $org->id, 'asset_id' => $asset->id, 'account_identifier' => 'demo']);
        $config = new ConnectorConfig($org->id, $account->id, $kind, $kind === 'sftp' ? 'sftp://sftp.example' : 'https://panel.example:2083',
            'demo', 'env:OPSHUB_CONNECTOR_BACKUP_TEST', $kind === 'sftp' ? ['/srv/app'] : [], $kind === 'sftp' ? 'SHA256:'.str_repeat('A', 43) : null);
        $connector = app(ConnectorRegistry::class)->configure($org, $owner, $config);
        foreach (['connection', ...($kind === 'sftp' ? ['sftp_read', 'file_backup'] : ['full_backup_trigger', 'backup_artifact_pull'])] as $capability) {
            app(CapabilityRecorder::class)->record($connector, 1, 'discover', new ConnectorResult('supported', $capability, null, fake: true));
        }
        ManagementAuthorization::create(['organization_id' => $org->id, 'resource_type' => 'hosting_account', 'resource_id' => $account->id,
            'allowed_action_classes' => ['observe', 'backup'], 'valid_until' => now('UTC')->addDay()]);
        $policy = app(BackupPolicies::class)->configure($org, $owner, $connector, app(BackupPolicySettings::class)->defaults(), true);
        app(BackupPolicies::class)->setPaused($org, $owner, false, 0);
        $capacity = new FixedBackupCapacity(new CapacitySnapshot(104857600, 1073741824, 1073741824, true, true));
        app()->instance(BackupCapacity::class, $capacity);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

        return [$org, $owner, $operator, $policy, $connector, $account, $capacity];
    }

    public function test_policy_owner_approval_version_scope_defaults_and_step_up_are_enforced(): void
    {
        [$org, $owner, $operator, $policy, $connector] = $this->fixture();
        $settings = app(BackupPolicySettings::class)->defaults();
        $this->assertSame(['daily' => 7, 'weekly' => 4, 'monthly' => 3], $policy->configuration['retention']);
        $this->assertSame('02:00', $policy->configuration['daily_at']);
        $this->assertSame(['files', 'database'], $policy->configuration['required_scopes']);
        $url = '/api/v1/organizations/'.$org->id.'/backup-policies';
        $this->actingAs($operator)->postJson($url, ['connector_id' => $connector->id, 'settings' => $settings, 'enabled' => true, 'version' => 1])->assertForbidden();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->subHour()->timestamp])
            ->postJson($url, ['connector_id' => $connector->id, 'settings' => $settings, 'enabled' => true, 'version' => 1])->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->postJson($url, ['connector_id' => $connector->id, 'settings' => $settings, 'enabled' => true, 'version' => 9])->assertConflict();
        $settings['included_paths'][0]['path'] = '../secret';
        $this->postJson($url, ['connector_id' => $connector->id, 'settings' => $settings, 'enabled' => true, 'version' => 1])->assertUnprocessable();
        $this->assertSame(1, $policy->fresh()->version);
        $this->assertArrayNotHasKey('configuration', $policy->toArray());
        $settings['included_paths'][0]['path'] = '';
        $this->postJson($url, ['connector_id' => $connector->id, 'settings' => $settings, 'enabled' => true, 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame('', $policy->fresh()->configuration['included_paths'][0]['path']);
    }

    public function test_queue_is_atomic_idempotent_and_one_account_run_keeps_database_gap_without_success(): void
    {
        [$org, $owner, , $policy, , $account, $capacity] = $this->fixture();
        $service = app(BackupRuns::class);
        $run = $service->enqueue($org, $owner, $policy, 1, 'canonical-backup-1');
        $this->assertSame($run->id, $service->enqueue($org, $owner, $policy, 1, 'canonical-backup-1')->id);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(0, $capacity->calls);
        $url = '/api/v1/organizations/'.$org->id.'/backup-policies/'.$policy->id.'/runs';
        $this->withHeader('Idempotency-Key', 'canonical-backup-1')->postJson($url, ['version' => 2])->assertConflict();
        $this->withHeader('Idempotency-Key', 'canonical-backup-2')->postJson($url, ['version' => 1])->assertConflict();
        (new PreflightBackup($run->id))->handle($service);
        $this->assertSame('ready', $run->fresh()->state);
        $this->assertTrue($run->fresh()->fake);
        $this->assertSame(['database'], $run->fresh()->preflight_evidence['coverage_gaps']);
        $this->assertSame($account->id, $run->fresh()->active_account_id);
        $this->assertNull($run->fresh()->completed_at);
        $this->assertDatabaseCount('backup_run_attempts', 1);
        $this->assertDatabaseCount('observations', 0);
        $this->assertSame(0, OutboxEvent::where('event_type', 'backup.verified')->count());
        $this->assertArrayNotHasKey('policy_snapshot', $run->fresh()->toArray());
    }

    public function test_insufficient_source_and_storage_or_unknown_stale_capacity_never_become_ready(): void
    {
        [$org, $owner, , $policy, , , $capacity] = $this->fixture('cpanel');
        $cases = [
            [new CapacitySnapshot(104857600, 1, 1073741824, true, true), 'QUOTA_INSUFFICIENT'],
            [new CapacitySnapshot(104857600, 1073741824, 1, true, true), 'QUOTA_INSUFFICIENT'],
            [new CapacitySnapshot(104857600, null, 1073741824, true, true), 'CAPACITY_UNKNOWN'],
            [new CapacitySnapshot(null, 1073741824, 1073741824, true, true), 'CAPACITY_UNKNOWN'],
            [new CapacitySnapshot(104857600, 1073741824, 1073741824, false, true), 'CAPACITY_UNKNOWN'],
            [new CapacitySnapshot(104857600, 1073741824, 1073741824, true, true, CarbonImmutable::now('UTC')->subMinutes(6)), 'CAPACITY_UNKNOWN'],
        ];
        foreach ($cases as $index => [$snapshot, $reason]) {
            $capacity->result = $snapshot;
            $run = app(BackupRuns::class)->enqueue($org, $owner, $policy, 1, 'quota-test-'.$index);
            app(BackupRuns::class)->preflight($run->id);
            $this->assertSame('blocked', $run->fresh()->state);
            $this->assertSame($reason, $run->fresh()->reason_code);
            $this->assertNull($run->fresh()->active_account_id);
        }
    }

    public function test_preflight_rechecks_authorization_versions_scope_capability_and_pause_before_capacity(): void
    {
        [$org, $owner, , $policy, $connector, , $capacity] = $this->fixture();
        $service = app(BackupRuns::class);
        $run = $service->enqueue($org, $owner, $policy, 1, 'auth-recheck-key');
        ManagementAuthorization::where('resource_id', $connector->hosting_account_id)->update(['valid_until' => now('UTC')->subSecond()]);
        $service->preflight($run->id);
        $this->assertSame('AUTHORIZATION_EXPIRED', $run->fresh()->reason_code);
        $this->assertSame(0, $capacity->calls);
        ManagementAuthorization::where('resource_id', $connector->hosting_account_id)->update(['valid_until' => now('UTC')->addDay()]);
        $run = $service->enqueue($org, $owner, $policy, 1, 'version-recheck-key');
        $connector->update(['version' => 2]);
        $service->preflight($run->id);
        $this->assertSame('STALE_VERSION', $run->fresh()->reason_code);
        $connector->update(['version' => 1]);
        $run = $service->enqueue($org, $owner, $policy, 1, 'capability-denied-key');
        app(CapabilityRecorder::class)->record($connector, 1, 'discover', new ConnectorResult('permission_denied', 'file_backup', 'PERMISSION_DENIED', fake: true));
        $service->preflight($run->id);
        $this->assertSame('CONNECTOR_UNAVAILABLE', $run->fresh()->reason_code);
        $this->assertSame(0, $capacity->calls);
    }

    public function test_expired_lease_and_kill_switch_keep_account_fence_for_reconcile_and_late_worker_cannot_finish(): void
    {
        [$org, $owner, , $policy] = $this->fixture();
        $service = app(BackupRuns::class);
        $run = $service->enqueue($org, $owner, $policy, 1, 'expired-backup-lease');
        $run->update(['state' => 'awaiting_source', 'lease_owner' => 'fictitious-old-worker', 'leased_until' => now()->subSecond()]);
        $service->recoverExpired($run->id);
        $this->assertSame('reconcile_required', $run->fresh()->state);
        $this->assertNotNull($run->fresh()->active_account_id);
        $this->withHeader('Idempotency-Key', 'new-after-expiry')->postJson('/api/v1/organizations/'.$org->id.'/backup-policies/'.$policy->id.'/runs', ['version' => 1])->assertConflict();
        app(BackupPolicies::class)->setPaused($org, $owner, true, 1);
        $service->preflight($run->id);
        $this->assertSame('reconcile_required', $run->fresh()->state);
        $this->assertSame('WRITE_PAUSED', $run->fresh()->reason_code);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'write_operations.paused']);
        $this->assertDatabaseCount('observations', 0);
    }

    public function test_rollbacks_remove_queue_run_and_native_gate_false_blocks_without_capacity_read(): void
    {
        [$org, $owner, , $policy, , , $capacity] = $this->fixture();
        try {
            DB::transaction(function () use ($org, $owner, $policy): void {
                app(BackupRuns::class)->enqueue($org, $owner, $policy, 1, 'rollback-backup-key');
                throw new \LogicException('rollback');
            });
        } catch (\LogicException) {
        }
        $this->assertDatabaseCount('backup_runs', 0);
        $this->assertDatabaseCount('jobs', 0);
        app()->bind(BackupCapacity::class, UnavailableBackupCapacity::class);
        app()->forgetInstance(BackupCapacity::class);
        $run = app(BackupRuns::class)->enqueue($org, $owner, $policy, 1, 'native-gate-false-key');
        app(BackupRuns::class)->preflight($run->id);
        $this->assertSame('NOT_CONFIGURED', $run->fresh()->reason_code);
        $this->assertFalse($run->fresh()->fake);
        $this->assertSame(0, $capacity->calls);
    }

    public function test_midflight_kill_switch_fences_preflight_and_account_scope_denies_api_read(): void
    {
        [$org, $owner, $operator, $policy, , , $capacity] = $this->fixture();
        $service = app(BackupRuns::class);
        $run = $service->enqueue($org, $owner, $policy, 1, 'midflight-pause-key');
        $capacity->duringInspect = fn () => app(BackupPolicies::class)->setPaused($org, $owner, true, 1);
        $service->preflight($run->id);
        $this->assertSame('reconcile_required', $run->fresh()->state);
        $this->assertNotNull($run->fresh()->active_account_id);
        $this->assertNull($run->fresh()->lease_owner);
        $this->actingAs($operator)->getJson('/api/v1/organizations/'.$org->id.'/backup-runs/'.$run->id)->assertNotFound();
        $this->actingAs($owner)->getJson('/api/v1/organizations/'.$org->id.'/backup-runs/'.$run->id)->assertOk();
    }

    public function test_two_accounts_cannot_overreserve_the_same_independent_destination(): void
    {
        [$org, $owner, , $policy, , $account, $capacity] = $this->fixture();
        $asset = Asset::create(['organization_id' => $org->id, 'kind' => 'hosting_account', 'canonical_identity' => 'fictitious-second-account']);
        $usage = $account->asset->usages()->first();
        AssetUsage::create(['organization_id' => $org->id, 'asset_id' => $asset->id, 'project_id' => $usage->project_id, 'environment_id' => $usage->environment_id, 'purpose' => 'hosting']);
        $second = HostingAccount::create(['organization_id' => $org->id, 'asset_id' => $asset->id, 'account_identifier' => 'second']);
        $connector = app(ConnectorRegistry::class)->configure($org, $owner, new ConnectorConfig($org->id, $second->id, 'sftp', 'sftp://sftp.example', 'second',
            'env:OPSHUB_CONNECTOR_BACKUP_TEST', ['/srv/app'], 'SHA256:'.str_repeat('A', 43)));
        foreach (['connection', 'sftp_read', 'file_backup'] as $capability) {
            app(CapabilityRecorder::class)->record($connector, 1, 'discover', new ConnectorResult('supported', $capability, null, fake: true));
        }
        ManagementAuthorization::create(['organization_id' => $org->id, 'resource_type' => 'hosting_account', 'resource_id' => $second->id, 'allowed_action_classes' => ['backup']]);
        $secondPolicy = app(BackupPolicies::class)->configure($org, $owner, $connector, app(BackupPolicySettings::class)->defaults(), true);
        $capacity->result = new CapacitySnapshot(104857600, 1073741824, 262144000, true, true);
        $service = app(BackupRuns::class);
        $first = $service->enqueue($org, $owner, $policy, 1, 'storage-reserve-first');
        $other = $service->enqueue($org, $owner, $secondPolicy, 1, 'storage-reserve-second');
        $service->preflight($first->id);
        $this->assertSame('ready', $first->fresh()->state);
        $this->assertSame(171966464, $first->fresh()->preflight_evidence['reserved_bytes']);
        $service->preflight($other->id);
        $this->assertSame('blocked', $other->fresh()->state);
        $this->assertSame('QUOTA_INSUFFICIENT', $other->fresh()->reason_code);
    }
}
