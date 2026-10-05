<?php

namespace App\Application\Backups;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\Connectors\ConnectorAccess;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\IdentityAccess\ProjectAccess;
use App\Application\TelegramNotifications\OutboxWriter;
use App\Models\BackupArtifact;
use App\Models\BackupInternalIncident;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Models\HostingAccount;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Internal backup incidents/tasks are distinct from HTTP/DNS website outage incidents. */
class BackupIssues
{
    public function tick(): int
    {
        $count = 0;
        foreach (BackupPolicy::where('enabled', true)->cursor() as $policy) {
            $count += DB::transaction(function () use ($policy): int {
                $org = Organization::whereKey($policy->organization_id)->lockForUpdate()->firstOrFail();
                $account = HostingAccount::whereKey($policy->hosting_account_id)->lockForUpdate()->firstOrFail();
                if (! $org->is_active) {
                    return 0;
                }
                $ids = $account->asset->usages()->pluck('project_id')->unique()->values()->all();
                $created = 0;
                foreach (BackupRun::where('hosting_account_id', $account->id)->whereIn('state', ['failed', 'partial', 'reconcile_required'])->cursor() as $run) {
                    $created += $this->open($org, $account, $policy, 'run:'.$run->id, 'failure', null, $run->id, $run->reason_code ?? 'BACKUP_INCOMPLETE', $run->impacted_project_ids);
                }
                foreach ($policy->configuration['required_scopes'] as $scope) {
                    $good = DB::table('backup_scope_goods')->where('hosting_account_id', $account->id)->where('scope', $scope)->first();
                    $artifact = $good ? BackupArtifact::find($good->backup_artifact_id) : null;
                    $usable = $artifact && $artifact->state === 'verified' && ! $artifact->source_observed_at->isFuture()
                        && BackupVerification::LEVELS[$artifact->verification_level] >= BackupVerification::LEVELS[$policy->configuration['verification']];
                    $origin = $usable ? CarbonImmutable::parse($good->source_observed_at, 'UTC') : $policy->created_at;
                    $key = 'rpo:'.$policy->id.':'.$scope.':'.($good?->backup_artifact_id ?? 'initial');
                    if ($origin->addHours($policy->configuration['rpo_hours'])->lessThanOrEqualTo(now('UTC'))) {
                        $created += $this->open($org, $account, $policy, $key, 'overdue', $scope, null, 'RPO_OVERDUE', $ids);
                    }
                }

                return $created;
            });
        }

        return $count;
    }

    private function open(Organization $org, HostingAccount $account, BackupPolicy $policy, string $key, string $kind, ?string $scope, ?int $runId, string $reason, array $ids): int
    {
        $incident = BackupInternalIncident::firstOrCreate(['deduplication_key' => $key], ['organization_id' => $org->id, 'hosting_account_id' => $account->id, 'backup_run_id' => $runId,
            'kind' => $kind, 'scope' => $scope, 'reason_code' => $reason, 'impacted_project_ids' => $ids, 'opened_at' => now('UTC')]);
        if (! $incident->wasRecentlyCreated) {
            return 0;
        }
        $actor = User::find($policy->approved_by);
        $assignee = $actor && app(ProjectAccess::class)->owner($actor, $org) ? $actor->id : null;
        DB::table('backup_recovery_tasks')->insert(['backup_internal_incident_id' => $incident->id, 'assignee_user_id' => $assignee, 'state' => 'open', 'due_at' => now('UTC')->addHours(4), 'version' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        app(OutboxWriter::class)->record($org, 'backup.issue.opened', 'backup_internal_incident', $incident->id, 1, ['route' => 'owner', 'severity' => 'warning', 'reason_code' => $reason, 'impacted_project_ids' => $ids]);

        return 1;
    }

    public function require(Organization $org, User $actor, BackupInternalIncident $incident): void
    {
        abort_unless($incident->organization_id === $org->id, 404);
        app(ConnectorAccess::class)->requireAccount($org, $actor, HostingAccount::findOrFail($incident->hosting_account_id));
        $access = app(ProjectAccess::class);
        abort_unless($access->owner($actor, $org) || $access->query($actor, $org)->whereIn('id', $incident->impacted_project_ids)->count() === count($incident->impacted_project_ids), 404);
    }

    public function resolve(Organization $org, User $actor, BackupInternalIncident $incident, int $version, string $evidence): BackupInternalIncident
    {
        abort_unless(preg_match('/^evidence:[a-zA-Z0-9_.-]{1,100}$/D', $evidence), 422);

        return DB::transaction(function () use ($org, $actor, $incident, $version, $evidence): BackupInternalIncident {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            HostingAccount::whereKey($incident->hosting_account_id)->lockForUpdate()->firstOrFail();
            $incident = BackupInternalIncident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $this->require($org->fresh(), $actor->fresh(), $incident);
            app(OrganizationAuthorizationService::class)->require($actor->fresh(), $org->fresh(), 'backup.run');
            abort_unless($incident->version === $version && $incident->state === 'open', 409);
            if ($incident->kind === 'overdue') {
                $good = DB::table('backup_scope_goods')->where('hosting_account_id', $incident->hosting_account_id)->where('scope', $incident->scope)->first();
                $policy = BackupPolicy::where('hosting_account_id', $incident->hosting_account_id)->sole();
                abort_unless($good && CarbonImmutable::parse($good->source_observed_at, 'UTC')->addHours($policy->configuration['rpo_hours'])->isFuture(), 409, 'RPO still overdue.');
            } else {
                abort_if(BackupRun::findOrFail($incident->backup_run_id)->active_account_id !== null, 409, 'Unknown operation requires reconciliation first.');
            }
            $incident->update(['state' => 'resolved', 'resolved_at' => now('UTC'), 'version' => $version + 1]);
            DB::table('backup_recovery_tasks')->where('backup_internal_incident_id', $incident->id)->update(['state' => 'resolved', 'resolution_evidence' => $evidence, 'version' => DB::raw('version + 1'), 'updated_at' => now('UTC')]);
            app(AuditWriter::class)->write($org, 'backup.issue.resolved', 'backup_internal_incident', $incident->id, 'success', $actor, after: ['evidence_reference' => $evidence]);

            return $incident;
        });
    }
}
