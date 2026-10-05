<?php

namespace App\Application\Backups;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\IdentityAccess\ProjectAccess;
use App\Application\Registry\ManagementAuthorizationService;
use App\Infrastructure\Backup\BackupDeletionPermit;
use App\Infrastructure\Backup\TemporarySourceCleaner;
use App\Infrastructure\Backup\UnconfiguredSourceCleaner;
use App\Models\BackupArtifact;
use App\Models\BackupPolicy;
use App\Models\Connector;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

class TemporarySourceCleanup
{
    public function execute(Organization $org, User $actor, BackupArtifact $artifact): string
    {
        // Source cleanup is a distinct, audited action. SFTP never gains write operations.
        [$run, $artifact, $cleaner, $token, $policyVersion] = app(BackupExecution::class)->locked($artifact->backup_run_id, function ($run) use ($org, $actor, $artifact): array {
            abort_unless(app(ProjectAccess::class)->owner($actor->fresh(), $org->fresh()), 403);
            $artifact = $artifact->fresh();
            abort_unless($artifact->source_cleanup_state === 'not_requested', 409);
            app(BackupArtifactAccess::class)->require($org, $actor, $artifact);
            $policy = BackupPolicy::findOrFail($run->backup_policy_id);
            $connector = Connector::findOrFail($policy->connector_id);
            abort_unless(($policy->configuration['cleanup_temporary_source'] ?? false) && $artifact->state === 'verified'
                && $artifact->verified_at && $run->transfer_status === 'independent_verified' && $run->source_status === 'retrieved'
                && $connector->kind === 'cpanel' && $connector->version === $run->connector_version && ! app(BackupPolicies::class)->paused($org->id)
                && app(BackupArtifactAccess::class)->protection($run)
                && app(ManagementAuthorizationService::class)->allows($org, 'hosting_account', $run->hosting_account_id, 'backup'), 409);
            $cleaner = app()->bound(TemporarySourceCleaner::class) ? app(TemporarySourceCleaner::class) : new UnconfiguredSourceCleaner;
            abort_unless($cleaner->fake() === $run->fake && ($run->fake ? app()->environment('testing') : config('opshub.live_connectors_enabled') && ! $connector->writes_paused && $connector->validation_state === 'validated_sandbox') && $cleaner->owned($run, $artifact), 409);
            $token = (string) Str::uuid();
            $artifact->update(['source_cleanup_state' => 'requesting', 'source_cleanup_lease' => $token, 'version' => $artifact->version + 1]);
            app(AuditWriter::class)->write($org, 'backup.temporary_source.intent', 'backup_artifact', $artifact->id, 'requesting', $actor);

            return [$run, $artifact, $cleaner, $token, $policy->version];
        });
        $permit = new BackupDeletionPermit(function () use ($org, $actor, $artifact, $cleaner, $token, $policyVersion): void {
            app(BackupExecution::class)->locked($artifact->backup_run_id, function ($currentRun) use ($org, $actor, $artifact, $cleaner, $token, $policyVersion): void {
                $current = $artifact->fresh();
                $policy = BackupPolicy::findOrFail($currentRun->backup_policy_id);
                $connector = Connector::findOrFail($policy->connector_id);
                abort_unless(app(ProjectAccess::class)->owner($actor->fresh(), $org->fresh()), 403);
                app(BackupArtifactAccess::class)->require($org->fresh(), $actor->fresh(), $current);
                abort_unless($current->source_cleanup_state === 'requesting' && $current->source_cleanup_lease === $token
                    && $policy->version === $policyVersion && $policy->enabled && ($policy->configuration['cleanup_temporary_source'] ?? false)
                    && $current->state === 'verified' && $current->verified_at && $currentRun->transfer_status === 'independent_verified'
                    && $currentRun->source_status === 'retrieved' && $connector->kind === 'cpanel' && $connector->version === $currentRun->connector_version
                    && ! app(BackupPolicies::class)->paused($org->id) && app(BackupArtifactAccess::class)->protection($currentRun)
                    && app(ManagementAuthorizationService::class)->allows($org, 'hosting_account', $currentRun->hosting_account_id, 'backup')
                    && $cleaner->fake() === $currentRun->fake && ($currentRun->fake ? app()->environment('testing') : config('opshub.live_connectors_enabled') && ! $connector->writes_paused && $connector->validation_state === 'validated_sandbox')
                    && $cleaner->owned($currentRun, $current), 409);
            });
        });
        $state = 'deleted';
        try {
            $cleaner->deleteOwned($run, $artifact, $permit);
            if (! $permit->authorized()) {
                throw new \LogicException('Cleanup adapter did not consume authorization.');
            }
        } catch (\Throwable) {
            // A denied permit proves no authorized write began. An issued permit leaves effects uncertain.
            $state = $permit->authorized() ? 'unknown' : 'blocked';
        }

        return app(BackupExecution::class)->locked($artifact->backup_run_id, function () use ($org, $actor, $artifact, $token, $state): string {
            $current = $artifact->fresh();
            if ($current->source_cleanup_lease !== $token || $current->source_cleanup_state !== 'requesting') {
                return 'unknown';
            }
            $current->update(['source_cleanup_state' => $state, 'source_cleanup_lease' => null]);
            app(AuditWriter::class)->write($org, 'backup.temporary_source.cleanup', 'backup_artifact', $artifact->id, $state, $actor);

            return $state;
        });
    }
}
