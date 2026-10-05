<?php

namespace Tests\Support;

use App\Application\Backups\BackupPolicies;
use App\Application\Backups\BackupPolicySettings;
use App\Application\Backups\BackupRuns;
use App\Application\Connectors\CapabilityRecorder;
use App\Application\Connectors\ConnectorRegistry;
use App\Infrastructure\Backup\BackupCapacity;
use App\Infrastructure\Backup\CapacitySnapshot;
use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorResult;
use App\Infrastructure\Testing\FixedBackupCapacity;
use App\Models\Asset;
use App\Models\AssetUsage;
use App\Models\Environment;
use App\Models\HostingAccount;
use App\Models\ManagementAuthorization;

trait BackupFixture
{
    protected function backupGraph(string $kind = 'cpanel'): array
    {
        [$org, $owner, , $project] = $this->telegramGraph();
        $org->refresh();
        $env = Environment::create(['organization_id' => $org->id, 'project_id' => $project->id, 'kind' => 'staging', 'display_name' => 'Fictitious backup staging']);
        $asset = Asset::create(['organization_id' => $org->id, 'kind' => 'hosting_account', 'canonical_identity' => 'fictitious-source-account']);
        AssetUsage::create(['organization_id' => $org->id, 'asset_id' => $asset->id, 'project_id' => $project->id, 'environment_id' => $env->id, 'purpose' => 'hosting']);
        $account = HostingAccount::create(['organization_id' => $org->id, 'asset_id' => $asset->id, 'account_identifier' => 'demo']);
        $connector = app(ConnectorRegistry::class)->configure($org, $owner, new ConnectorConfig($org->id, $account->id, $kind,
            $kind === 'cpanel' ? 'https://panel.example:2083' : 'sftp://sftp.example', 'demo', 'env:OPSHUB_CONNECTOR_SOURCE_TEST',
            $kind === 'sftp' ? ['/srv/app'] : [], $kind === 'sftp' ? 'SHA256:'.str_repeat('A', 43) : null));
        foreach (['connection', ...($kind === 'cpanel' ? ['full_backup_trigger', 'backup_artifact_pull'] : ['sftp_read', 'file_backup'])] as $capability) {
            app(CapabilityRecorder::class)->record($connector, 1, 'discover', new ConnectorResult('supported', $capability, null, fake: true));
        }
        ManagementAuthorization::create(['organization_id' => $org->id, 'resource_type' => 'hosting_account', 'resource_id' => $account->id, 'allowed_action_classes' => ['observe', 'backup'], 'valid_until' => now('UTC')->addDay()]);
        $policy = app(BackupPolicies::class)->configure($org, $owner, $connector, app(BackupPolicySettings::class)->defaults(), true);
        app(BackupPolicies::class)->setPaused($org, $owner, false, 0);
        $capacity = new FixedBackupCapacity(new CapacitySnapshot(104857600, 1073741824, 1073741824, true, true));
        app()->instance(BackupCapacity::class, $capacity);
        $run = app(BackupRuns::class)->enqueue($org, $owner, $policy, 1, 'fixture-source-key');
        app(BackupRuns::class)->preflight($run->id);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

        return [$org, $owner, $run->fresh(), $connector, $policy, $capacity];
    }
}
