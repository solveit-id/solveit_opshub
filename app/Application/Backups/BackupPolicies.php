<?php

namespace App\Application\Backups;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\Connectors\ConnectorAccess;
use App\Application\IdentityAccess\ProjectAccess;
use App\Application\TelegramNotifications\OutboxWriter;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Models\Connector;
use App\Models\HostingAccount;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BackupPolicies
{
    public function configure(Organization $org, User $actor, Connector $connector, array $settings, bool $enabled, ?int $version = null): BackupPolicy
    {
        $settings = app(BackupPolicySettings::class)->validate($settings);

        return DB::transaction(function () use ($org, $actor, $connector, $settings, $enabled, $version): BackupPolicy {
            $org = $org->fresh();
            $actor = $actor->fresh();
            abort_unless(app(ProjectAccess::class)->owner($actor, $org), 403);
            abort_unless($connector->organization_id === $org->id, 404);
            $account = HostingAccount::whereKey($connector->hosting_account_id)->lockForUpdate()->firstOrFail();
            app(ConnectorAccess::class)->requireAccount($org, $actor, $account);
            $connector = $connector->fresh();
            if ($connector->kind === 'sftp') {
                foreach ($settings['included_paths'] as $path) {
                    abort_unless(isset($connector->configuration['roots'][$path['root_index']]), 422);
                }
            } else {
                // Provider full-account backup cannot silently honor project-only includes/excludes.
                abort_unless(count($settings['included_paths']) === 1 && $settings['included_paths'][0]['root_index'] === 0
                    && $settings['included_paths'][0]['path'] === '' && $settings['excluded_paths'] === [], 422);
            }
            $policy = BackupPolicy::where('hosting_account_id', $account->id)->lockForUpdate()->first();
            abort_unless($policy ? $version === $policy->version : $version === null, 409);
            $policy ??= new BackupPolicy(['organization_id' => $org->id, 'hosting_account_id' => $account->id, 'version' => 0]);
            $policy->fill(['connector_id' => $connector->id, 'version' => $policy->version + 1, 'configuration' => $settings,
                'enabled' => $enabled, 'approved_by' => $actor->id, 'approved_at' => now('UTC')])->save();
            app(AuditWriter::class)->write($org, 'backup.policy.approved', 'backup_policy', $policy->id, 'success', $actor,
                after: ['version' => $policy->version, 'enabled' => $enabled, 'required_scopes' => $settings['required_scopes']]);

            return $policy;
        });
    }

    public function setPaused(Organization $org, User $actor, bool $paused, int $version): array
    {
        return DB::transaction(function () use ($org, $actor, $paused, $version): array {
            $org = Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(ProjectAccess::class)->owner($actor->fresh(), $org), 403);
            $control = DB::table('backup_write_controls')->where('organization_id', $org->id)->first();
            abort_unless($version === ($control?->version ?? 0), 409);
            DB::table('backup_write_controls')->updateOrInsert(['organization_id' => $org->id], ['paused' => $paused, 'version' => $version + 1, 'updated_at' => now('UTC'), 'created_at' => $control?->created_at ?? now('UTC')]);
            if ($paused) {
                // Outstanding runs retain the account fence. A pause never claims a remote action was cancelled.
                BackupRun::forOrganization($org)->whereNotNull('active_account_id')->update(['state' => 'reconcile_required', 'reason_code' => 'WRITE_PAUSED', 'lease_owner' => null, 'leased_until' => null]);
                app(OutboxWriter::class)->record($org, 'write_operations.paused', 'organization', $org->id, $version + 1, ['severity' => 'warning', 'route' => 'owner']);
            }
            app(AuditWriter::class)->write($org, 'backup.write_control.changed', 'organization', $org->id, 'success', $actor, after: ['paused' => $paused, 'version' => $version + 1]);

            return ['paused' => $paused, 'version' => $version + 1];
        });
    }

    public function paused(int $orgId): bool
    {
        return (bool) (DB::table('backup_write_controls')->where('organization_id', $orgId)->value('paused') ?? true);
    }
}
