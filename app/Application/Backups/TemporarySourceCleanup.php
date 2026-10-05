<?php

namespace App\Application\Backups;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\IdentityAccess\ProjectAccess;
use App\Application\Registry\ManagementAuthorizationService;
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
        [$run, $artifact, $cleaner, $token] = app(BackupExecution::class)->locked($artifact->backup_run_id, function ($run) use ($org, $actor, $artifact): array {
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

            return [$run, $artifact, $cleaner, $token];
        });
        $state = 'deleted';
        try {
            $cleaner->deleteOwned($run, $artifact);
        } catch (\Throwable) {
            $state = 'unknown';
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
