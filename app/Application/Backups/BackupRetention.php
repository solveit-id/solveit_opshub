<?php

namespace App\Application\Backups;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\IdentityAccess\ProjectAccess;
use App\Application\Registry\ManagementAuthorizationService;
use App\Infrastructure\Backup\PrivateObjectStore;
use App\Jobs\ApplyBackupRetention;
use App\Models\BackupArtifact;
use App\Models\BackupPolicy;
use App\Models\BackupRetentionReport;
use App\Models\BackupRun;
use App\Models\Connector;
use App\Models\HostingAccount;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackupRetention
{
    public function approve(Organization $org, User $actor, BackupPolicy $policy, int $version, array $retention, bool $cleanupSource): BackupPolicy
    {
        abort_unless($policy->organization_id === $org->id, 404);
        $settings = [...$policy->fresh()->configuration, 'retention' => $retention, 'cleanup_temporary_source' => $cleanupSource];

        return app(BackupPolicies::class)->configure($org, $actor, Connector::findOrFail($policy->connector_id), $settings, $policy->enabled, $version);
    }

    public function dryRun(Organization $org, User $actor, BackupPolicy $policy): BackupRetentionReport
    {
        $this->owner($org, $actor, $policy);
        $policy = $policy->fresh();
        $tiers = ['daily' => [], 'weekly' => [], 'monthly' => []];
        $keep = [];
        // Cursor uses small metadata rows; one artifact may occupy multiple tiers, never copied.
        foreach (BackupArtifact::select(['id', 'organization_id', 'hosting_account_id', 'backup_run_id', 'state', 'version', 'source_observed_at', 'legal_hold', 'restore_pending'])->where('hosting_account_id', $policy->hosting_account_id)->whereNull('deleted_at')
            ->orderByDesc('source_observed_at')->orderByDesc('id')->cursor() as $artifact) {
            app(BackupArtifactAccess::class)->require($org, $actor, $artifact);
            if ($artifact->state !== 'verified') {
                continue;
            }
            $local = $artifact->source_observed_at->setTimezone($policy->configuration['timezone']);
            foreach (['daily' => $local->format('Y-m-d'), 'weekly' => $local->format('o-W'), 'monthly' => $local->format('Y-m')] as $tier => $bucket) {
                if (! isset($tiers[$tier][$bucket]) && count($tiers[$tier]) < $policy->configuration['retention'][$tier]) {
                    $tiers[$tier][$bucket] = $artifact->id;
                    $keep[$artifact->id][] = $tier;
                }
            }
        }
        $decisions = [];
        $deleteCount = 0;
        foreach (BackupArtifact::select(['id', 'organization_id', 'hosting_account_id', 'backup_run_id', 'state', 'version', 'source_observed_at', 'legal_hold', 'restore_pending'])->where('hosting_account_id', $policy->hosting_account_id)->whereNull('deleted_at')->orderBy('source_observed_at')->orderBy('id')->cursor() as $artifact) {
            app(BackupArtifactAccess::class)->require($org, $actor, $artifact);
            $reasons = $this->protected($artifact);
            if (isset($keep[$artifact->id])) {
                $reasons[] = 'retention_tier';
            }
            if ($reasons && count($decisions) >= 500) {
                continue;
            }
            $decisions[] = ['artifact_id' => $artifact->id, 'version' => $artifact->version, 'decision' => $reasons ? 'keep' : 'delete', 'reasons' => $reasons, 'tiers' => $keep[$artifact->id] ?? []];
            if (! $reasons && ++$deleteCount >= 500) {
                break;
            } // Bounded candidates; protected rows cannot starve future cleanup.
        }
        $report = BackupRetentionReport::create(['organization_id' => $org->id, 'backup_policy_id' => $policy->id, 'policy_version' => $policy->version,
            'actor_user_id' => $actor->id, 'decisions' => $decisions, 'expires_at' => now('UTC')->addMinutes(30)]);
        app(AuditWriter::class)->write($org, 'backup.retention.dry_run', 'backup_retention_report', $report->id, 'success', $actor, after: ['policy_version' => $policy->version, 'artifacts' => count($decisions)]);

        return $report;
    }

    public function enqueue(Organization $org, User $actor, BackupRetentionReport $report): void
    {
        abort_unless($report->organization_id === $org->id, 404);
        $policy = BackupPolicy::findOrFail($report->backup_policy_id);
        $this->owner($org, $actor, $policy);
        DB::transaction(function () use ($org, $actor, $report, $policy): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $report = BackupRetentionReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            $this->owner($org->fresh(), $actor->fresh(), $policy->fresh());
            abort_unless($report->state === 'dry_run' && $report->expires_at->isFuture() && $report->policy_version === $policy->fresh()->version, 409);
            abort_if(app(BackupPolicies::class)->paused($org->id), 409);
            $report->update(['state' => 'queued', 'actor_user_id' => $actor->id]);
            ApplyBackupRetention::dispatch($report->id)->onConnection('database')->onQueue('backup');
        });
    }

    public function apply(int $id): void
    {
        $report = DB::transaction(function () use ($id): ?BackupRetentionReport {
            $report = BackupRetentionReport::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($report->state !== 'queued') {
                return null;
            }
            $report->update(['state' => 'applying']);

            return $report;
        });
        if (! $report) {
            return;
        }
        $results = [];
        foreach ($report->decisions as $decision) {
            if ($decision['decision'] !== 'delete') {
                continue;
            }
            $artifact = BackupArtifact::findOrFail($decision['artifact_id']);
            try {
                $claim = app(BackupExecution::class)->locked($artifact->backup_run_id, function (BackupRun $run) use ($report, $artifact, $decision): ?array {
                    $policy = BackupPolicy::findOrFail($report->backup_policy_id);
                    $org = Organization::findOrFail($report->organization_id);
                    $actor = User::findOrFail($report->actor_user_id);
                    $artifact = $artifact->fresh();
                    $this->owner($org, $actor, $policy);
                    app(BackupArtifactAccess::class)->require($org, $actor, $artifact);
                    if ($report->policy_version !== $policy->version || ! $report->expires_at->isFuture() || app(BackupPolicies::class)->paused($org->id)) {
                        return null;
                    }
                    if (! app(ManagementAuthorizationService::class)->allows($org, 'hosting_account', $run->hosting_account_id, 'backup')) {
                        return null;
                    }
                    if ($artifact->hosting_account_id !== $policy->hosting_account_id || $artifact->version !== $decision['version'] || $this->protected($artifact)
                        || ! app(BackupArtifactAccess::class)->protection($run)) {
                        return null;
                    }
                    $token = (string) Str::uuid();
                    $artifact->update(['state' => 'deleting', 'delete_lease' => $token, 'version' => $artifact->version + 1]);

                    return [$artifact, $token];
                });
            } catch (\Throwable) {
                $results[] = ['artifact_id' => $artifact->id, 'state' => 'authorization_or_scope_blocked'];
                $report->update(['state' => 'blocked', 'results' => $results]);

                return;
            }
            if (! $claim) {
                $results[] = ['artifact_id' => $artifact->id, 'state' => 'skipped_after_recheck'];

                continue;
            }
            [$artifact, $token] = $claim;
            try {
                app(PrivateObjectStore::class)->delete($artifact->object_reference, $artifact->object_version);
                $state = 'deleted';
            } catch (\Throwable) {
                $state = 'delete_unknown';
            }
            app(BackupExecution::class)->locked($artifact->backup_run_id, function () use ($artifact, $token, $state, $report): void {
                $current = $artifact->fresh();
                if ($current->delete_lease !== $token || $current->state !== 'deleting') {
                    return;
                }
                $current->update(['state' => $state, 'deleted_at' => $state === 'deleted' ? now('UTC') : null, 'delete_lease' => null, 'reason_code' => $state === 'deleted' ? null : 'STORAGE_DELETE_UNKNOWN']);
                app(AuditWriter::class)->write(Organization::findOrFail($report->organization_id), 'backup.retention.deleted', 'backup_artifact', $current->id, $state, User::findOrFail($report->actor_user_id));
            });
            $results[] = ['artifact_id' => $artifact->id, 'state' => $state];
        }
        $report->update(['state' => 'completed', 'results' => $results]);
    }

    public function protected(BackupArtifact $artifact): array
    {
        $reasons = [];
        if ($artifact->state !== 'verified') {
            $reasons[] = 'unverified_or_uncertain';
        }
        if ($artifact->legal_hold) {
            $reasons[] = 'legal_hold';
        }
        if ($artifact->restore_pending) {
            $reasons[] = 'restore_pending';
        }
        if (DB::table('backup_scope_goods')->where('backup_artifact_id', $artifact->id)->exists()) {
            $reasons[] = 'last_known_good';
        }
        if (DB::table('backup_download_links')->where('backup_artifact_id', $artifact->id)->where('expires_at', '>', now('UTC'))->exists()) {
            $reasons[] = 'active_download';
        }

        return $reasons;
    }

    public function reconcile(Organization $org, User $actor, BackupArtifact $artifact, int $version): BackupArtifact
    {
        return app(BackupExecution::class)->locked($artifact->backup_run_id, function (BackupRun $run) use ($org, $actor, $artifact, $version): BackupArtifact {
            $this->owner($org->fresh(), $actor->fresh(), BackupPolicy::findOrFail($run->backup_policy_id));
            $artifact = $artifact->fresh();
            app(BackupArtifactAccess::class)->require($org, $actor, $artifact);
            abort_unless($artifact->version === $version && in_array($artifact->state, ['deleting', 'delete_unknown'], true), 409);
            abort_unless(app(BackupArtifactAccess::class)->protection($run), 409);
            $store = app(PrivateObjectStore::class);
            $presence = $store->presence($artifact->object_reference, $artifact->object_version);
            if ($presence === 'present') {
                $meta = $store->metadata($artifact->object_reference, $artifact->object_version);
                abort_unless(($meta['version'] ?? null) === $artifact->object_version && ($meta['sha256'] ?? null) === $artifact->encrypted_sha256 && ($meta['bytes'] ?? null) === $artifact->encrypted_bytes, 409);
                $artifact->update(['state' => 'verified', 'delete_lease' => null, 'reason_code' => null, 'version' => $version + 1]);
            } elseif ($presence === 'missing') {
                $artifact->update(['state' => 'deleted', 'delete_lease' => null, 'deleted_at' => now('UTC'), 'reason_code' => null, 'version' => $version + 1]);
            }
            app(AuditWriter::class)->write($org, 'backup.retention.reconciled', 'backup_artifact', $artifact->id, $presence, $actor);

            return $artifact;
        });
    }

    private function owner(Organization $org, User $actor, BackupPolicy $policy): void
    {
        abort_unless($policy->organization_id === $org->id && app(ProjectAccess::class)->owner($actor, $org), 403);
        app(BackupRuns::class)->requireActor($org, $actor, HostingAccount::findOrFail($policy->hosting_account_id));
    }
}
