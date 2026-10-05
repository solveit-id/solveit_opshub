<?php

namespace App\Application\Backups;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\Connectors\ConnectorAccess;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\IdentityAccess\ProjectAccess;
use App\Application\Registry\ManagementAuthorizationService;
use App\Infrastructure\Backup\BackupCapacity;
use App\Infrastructure\Backup\CapacitySnapshot;
use App\Jobs\PreflightBackup;
use App\Jobs\RunCpanelBackup;
use App\Jobs\RunSftpBackup;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Models\Connector;
use App\Models\ConnectorAssessment;
use App\Models\HostingAccount;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackupRuns
{
    public function enqueue(Organization $org, User $actor, BackupPolicy $policy, int $version, string $key): BackupRun
    {
        return DB::transaction(function () use ($org, $actor, $policy, $version, $key): BackupRun {
            $org = Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $account = HostingAccount::whereKey($policy->hosting_account_id)->lockForUpdate()->firstOrFail();
            abort_unless($policy->organization_id === $org->id, 404);
            $this->requireActor($org, $actor->fresh(), $account);
            abort_unless(preg_match('/^[a-zA-Z0-9_.-]{8,100}$/D', $key), 422);
            $policy = $policy->fresh();
            $digest = hash('sha256', json_encode([$policy->id, $version], JSON_THROW_ON_ERROR));
            $previous = BackupRun::where('backup_policy_id', $policy->id)->where('actor_user_id', $actor->id)->where('idempotency_key', $key)->first();
            if ($previous) {
                abort_unless($previous->request_digest === $digest, 409);

                return $previous;
            }
            abort_unless($policy->version === $version && $policy->enabled, 409);
            abort_if(BackupRun::where('hosting_account_id', $account->id)->whereNotNull('active_account_id')->exists(), 409, 'Account run requires completion or reconciliation.');
            $connector = Connector::findOrFail($policy->connector_id);
            $run = BackupRun::create(['organization_id' => $org->id, 'hosting_account_id' => $account->id, 'backup_policy_id' => $policy->id,
                'actor_user_id' => $actor->id, 'run_reference' => (string) Str::uuid(), 'policy_version' => $version, 'connector_version' => $connector->version,
                'policy_snapshot' => $policy->configuration, 'impacted_project_ids' => $this->projectIds($account), 'idempotency_key' => $key, 'request_digest' => $digest]);
            PreflightBackup::dispatch($run->id)->onConnection('database')->onQueue('backup');
            app(AuditWriter::class)->write($org, 'backup.run.requested', 'backup_run', $run->id, 'queued', $actor,
                after: ['policy_version' => $version, 'impacted_project_ids' => $run->impacted_project_ids]);

            return $run;
        });
    }

    public function preflight(int $id): void
    {
        $claim = DB::transaction(function () use ($id): ?array {
            $run = BackupRun::findOrFail($id);
            Organization::whereKey($run->organization_id)->lockForUpdate()->firstOrFail();
            HostingAccount::whereKey($run->hosting_account_id)->lockForUpdate()->firstOrFail();
            $run = BackupRun::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($run->state !== 'queued') {
                return null;
            }
            $token = (string) Str::uuid();
            $run->update(['state' => 'preflight', 'attempts' => $run->attempts + 1, 'lease_owner' => $token, 'leased_until' => now('UTC')->addSeconds(90)]);
            DB::table('backup_run_attempts')->insert(['backup_run_id' => $id, 'number' => $run->attempts, 'mode' => 'preflight', 'state' => 'claimed', 'started_at' => now('UTC')]);

            return [$run, $token];
        });
        if (! $claim) {
            return;
        }
        [$run, $token] = $claim;
        $capacity = app(BackupCapacity::class);
        $snapshot = null;
        $reason = null;
        try {
            $reason = $this->eligibility($run, $capacity->fake());
            if ($reason === null) {
                $snapshot = $capacity->inspect($run, Connector::findOrFail(BackupPolicy::findOrFail($run->backup_policy_id)->connector_id));
            }
            $reason ??= $this->capacityReason($run, $snapshot, $capacity->fake());
        } catch (\Throwable) {
            $reason = 'PREFLIGHT_UNAVAILABLE';
        }
        DB::transaction(function () use ($run, $token, $reason, $snapshot, $capacity): void {
            Organization::whereKey($run->organization_id)->lockForUpdate()->firstOrFail();
            HostingAccount::whereKey($run->hosting_account_id)->lockForUpdate()->firstOrFail();
            $run = BackupRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->state !== 'preflight' || $run->lease_owner !== $token || ! $run->leased_until?->isFuture()) {
                return;
            }
            $reason ??= $this->eligibility($run, $capacity->fake());
            $reason ??= $this->capacityReason($run, $snapshot, $capacity->fake());
            $reserved = 0;
            if ($reason === null) {
                // Organization lock serializes reservations for this organization-scoped destination.
                $other = BackupRun::forOrganization($run->organization_id)->where('id', '!=', $run->id)->whereNotNull('active_account_id')
                    ->where('policy_snapshot->destination_reference', $run->policy_snapshot['destination_reference'])->get(['preflight_evidence']);
                $alreadyReserved = $other->sum(fn ($item) => $item->preflight_evidence['reserved_bytes'] ?? 0);
                $reserved = $snapshot->estimatedBytes + max(67108864, (int) ceil($snapshot->estimatedBytes * 0.2));
                if ($reserved > $snapshot->storageFreeBytes - $alreadyReserved) {
                    $reason = 'QUOTA_INSUFFICIENT';
                    $reserved = 0;
                }
            }
            $gaps = [];
            $connector = Connector::findOrFail(BackupPolicy::findOrFail($run->backup_policy_id)->connector_id);
            if ($connector->kind === 'sftp') {
                $gaps = array_values(array_diff($run->policy_snapshot['required_scopes'], ['files']));
            }
            $run->update(['state' => $reason ? 'blocked' : 'ready', 'reason_code' => $reason, 'fake' => $snapshot?->fake ?? ($capacity->fake() && app()->environment('testing')),
                'preflight_evidence' => ['estimated_bytes' => $snapshot?->estimatedBytes, 'source_free_bytes' => $snapshot?->sourceFreeBytes,
                    'storage_free_bytes' => $snapshot?->storageFreeBytes, 'independent' => $snapshot?->independent ?? false,
                    'observed_at' => $snapshot?->observedAt->toIso8601String(), 'coverage_gaps' => $gaps, 'reserved_bytes' => $reserved],
                'lease_owner' => null, 'leased_until' => null, 'completed_at' => $reason ? now('UTC') : null]);
            DB::table('backup_run_attempts')->where('backup_run_id', $run->id)->where('number', $run->attempts)
                ->update(['state' => 'finished', 'reason_code' => $reason, 'completed_at' => now('UTC')]);
            if (! $reason && $connector->kind === 'cpanel') {
                RunCpanelBackup::dispatch($run->id)->onConnection('database')->onQueue('backup');
            } elseif (! $reason && $connector->kind === 'sftp') {
                RunSftpBackup::dispatch($run->id)->onConnection('database')->onQueue('backup');
            }
        });
    }

    public function eligibility(BackupRun $run, bool $fake): ?string
    {
        $org = Organization::findOrFail($run->organization_id);
        $account = HostingAccount::findOrFail($run->hosting_account_id);
        try {
            $this->requireActor($org, User::findOrFail($run->actor_user_id), $account);
        } catch (\Throwable) {
            return 'AUTHORIZATION_EXPIRED';
        }
        if (! app(ManagementAuthorizationService::class)->allows($org, 'hosting_account', $account->id, 'backup')) {
            return 'AUTHORIZATION_EXPIRED';
        }
        if ($this->projectIds($account) !== $run->impacted_project_ids) {
            return 'SCOPE_CHANGED';
        }
        if (app(BackupPolicies::class)->paused($org->id)) {
            return 'WRITE_PAUSED';
        }
        $policy = BackupPolicy::findOrFail($run->backup_policy_id);
        $connector = Connector::findOrFail($policy->connector_id);
        if (! $policy->enabled || $policy->version !== $run->policy_version || $connector->version !== $run->connector_version) {
            return 'STALE_VERSION';
        }
        if ($fake && ! app()->environment('testing')) {
            return 'NOT_CONFIGURED';
        }
        if (! $fake && (! config('opshub.live_connectors_enabled') || $connector->writes_paused || $connector->validation_state !== 'validated_sandbox')) {
            return 'NOT_CONFIGURED';
        }
        if ($connector->state !== 'connected') {
            return 'CONNECTOR_UNAVAILABLE';
        }
        if ($connector->kind === 'sftp' && ! in_array('files', $run->policy_snapshot['required_scopes'], true)) {
            return 'UNSUPPORTED_CAPABILITY';
        }
        $capabilities = $connector->kind === 'cpanel' ? ['full_backup_trigger', 'backup_artifact_pull'] : ['sftp_read', 'file_backup'];
        foreach ($capabilities as $capability) {
            $assessment = ConnectorAssessment::where('connector_id', $connector->id)->where('configuration_version', $connector->version)
                ->where('capability', $capability)->orderByDesc('observed_at')->orderByDesc('id')->first();
            if (! $assessment || $assessment->status !== 'supported' || $assessment->source !== ($fake ? 'fake' : 'provider')
                || $assessment->observed_at->isFuture() || $assessment->observed_at->lessThan(now('UTC')->subDay())) {
                return 'UNSUPPORTED_CAPABILITY';
            }
        }

        return null;
    }

    private function capacityReason(BackupRun $run, ?CapacitySnapshot $snapshot, bool $fake): ?string
    {
        if (! $snapshot || $snapshot->fake !== $fake || ! $snapshot->independent || $snapshot->estimatedBytes === null || $snapshot->storageFreeBytes === null
            || $snapshot->observedAt->isFuture() || $snapshot->observedAt->lessThan(now('UTC')->subMinutes(5))) {
            return 'CAPACITY_UNKNOWN';
        }
        if ($snapshot->estimatedBytes > $run->policy_snapshot['max_bytes']) {
            return 'LIMIT_EXCEEDED';
        }
        $needed = $snapshot->estimatedBytes + max(67108864, (int) ceil($snapshot->estimatedBytes * 0.2));
        if ($snapshot->storageFreeBytes < $needed) {
            return 'QUOTA_INSUFFICIENT';
        }
        $connector = Connector::findOrFail(BackupPolicy::findOrFail($run->backup_policy_id)->connector_id);
        if ($connector->kind === 'cpanel' && $snapshot->sourceFreeBytes === null) {
            return 'CAPACITY_UNKNOWN';
        }
        if ($snapshot->sourceFreeBytes !== null && $snapshot->sourceFreeBytes < $needed) {
            return 'QUOTA_INSUFFICIENT';
        }

        return null;
    }

    public function recoverExpired(int $id): BackupRun
    {
        return DB::transaction(function () use ($id): BackupRun {
            $run = BackupRun::findOrFail($id);
            Organization::whereKey($run->organization_id)->lockForUpdate()->firstOrFail();
            HostingAccount::whereKey($run->hosting_account_id)->lockForUpdate()->firstOrFail();
            $run = BackupRun::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($run->active_account_id && $run->leased_until && ! $run->leased_until->isFuture()) {
                $run->update(['state' => 'reconcile_required', 'reason_code' => 'RECONCILE_REQUIRED', 'lease_owner' => null, 'leased_until' => null]);
                DB::table('backup_run_attempts')->where('backup_run_id', $id)->where('number', $run->attempts)->update(['state' => 'expired', 'reason_code' => 'RECONCILE_REQUIRED', 'completed_at' => now('UTC')]);
            }

            return $run;
        });
    }

    public function requireActor(Organization $org, User $actor, HostingAccount $account): void
    {
        app(ConnectorAccess::class)->requireAccount($org, $actor, $account);
        app(OrganizationAuthorizationService::class)->require($actor, $org, 'backup.run');
    }

    public function requireRead(Organization $org, User $actor, BackupRun $run): void
    {
        abort_unless($run->organization_id === $org->id, 404);
        app(ConnectorAccess::class)->requireAccount($org, $actor, HostingAccount::findOrFail($run->hosting_account_id));
        $access = app(ProjectAccess::class);
        if (! $access->owner($actor, $org)) {
            abort_unless($access->query($actor, $org)->whereIn('id', $run->impacted_project_ids)->count() === count($run->impacted_project_ids), 404);
        }
    }

    private function projectIds(HostingAccount $account): array
    {
        return $account->asset->usages()->orderBy('project_id')->pluck('project_id')->unique()->values()->all();
    }
}
