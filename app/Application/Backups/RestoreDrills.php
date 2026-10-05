<?php

namespace App\Application\Backups;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\Registry\ManagementAuthorizationService;
use App\Infrastructure\Backup\BackupKeyResolver;
use App\Infrastructure\Backup\ManifestIdentity;
use App\Infrastructure\Backup\PrivateObjectStore;
use App\Infrastructure\Backup\RestoreTarget;
use App\Infrastructure\Backup\SftpBundleInspection;
use App\Infrastructure\Backup\SodiumEncryptedStream;
use App\Infrastructure\Backup\UnconfiguredRestoreTarget;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Jobs\RunRestoreDrill;
use App\Models\BackupArtifact;
use App\Models\BackupRestoreDrill;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RestoreDrills
{
    public function enqueue(Organization $org, User $actor, BackupArtifact $artifact, string $target, string $kind): BackupRestoreDrill
    {
        abort_unless($kind === 'isolated' && preg_match('/^isolated:[a-zA-Z0-9_-]{1,100}$/D', $target), 422);

        return app(BackupExecution::class)->locked($artifact->backup_run_id, function ($run) use ($org, $actor, $artifact, $target): BackupRestoreDrill {
            $artifact = $artifact->fresh();
            $this->require($org, $actor->fresh(), $artifact);
            abort_unless($artifact->state === 'verified' && ! $artifact->restore_pending && ! app(BackupPolicies::class)->paused($org->id), 409);
            $unsupported = $artifact->source_kind !== 'sftp';
            $drill = BackupRestoreDrill::create(['organization_id' => $org->id, 'backup_artifact_id' => $artifact->id, 'operator_user_id' => $actor->id,
                'target_reference' => $target, 'workspace_reference' => (string) Str::uuid(), 'target_kind' => 'isolated', 'runbook' => $unsupported ? 'cpanel-provider-manual-v1' : 'isolated-file-drill-v1',
                'state' => $unsupported ? 'manual_required' : 'queued', 'reason_code' => $unsupported ? 'UNSUPPORTED_FULL_RESTORE' : null, 'fake' => $run->fake]);
            $artifact->update(['restore_pending' => true, 'version' => $artifact->version + 1]);
            if (! $unsupported) {
                RunRestoreDrill::dispatch($drill->id)->onConnection('database')->onQueue('backup');
            }
            app(AuditWriter::class)->write($org, 'backup.restore_drill.requested', 'backup_restore_drill', $drill->id, $drill->state, $actor, after: ['artifact_id' => $artifact->id, 'runbook' => $drill->runbook, 'target_kind' => 'isolated']);

            return $drill;
        });
    }

    public function execute(int $id): void
    {
        $reference = BackupRestoreDrill::findOrFail($id);
        $artifact = BackupArtifact::findOrFail($reference->backup_artifact_id);
        $claim = app(BackupExecution::class)->locked($artifact->backup_run_id, function ($run) use ($id, $artifact): ?array {
            $drill = BackupRestoreDrill::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($drill->state !== 'queued') {
                return null;
            }
            if (! app(BackupExecution::class)->available($run)) {
                RunRestoreDrill::dispatch($drill->id)->onConnection('database')->onQueue('backup')->delay(now('UTC')->addSeconds(30));

                return null;
            }
            $token = (string) Str::uuid();
            $drill->update(['state' => 'running', 'lease_owner' => $token, 'leased_until' => now('UTC')->addSeconds(90)]);

            return [$run, $drill, $artifact->fresh(), $token];
        });
        if (! $claim) {
            return;
        }
        [$run, $drill, $artifact, $token] = $claim;
        $target = app()->bound(RestoreTarget::class) ? app(RestoreTarget::class) : new UnconfiguredRestoreTarget;
        $key = null;
        $checks = [];
        $state = 'failed';
        $reason = 'RESTORE_CHECK_FAILED';
        $evidence = [];
        try {
            $this->guard($drill, $artifact, $token);
            $facts = $target->inspect($drill);
            if (($facts['configured'] ?? false) !== true || ($facts['authorized'] ?? false) !== true || ($facts['isolated'] ?? false) !== true || ($facts['production'] ?? true) !== false || ($facts['fake'] ?? null) !== $run->fake
                || (! $run->fake && ($facts['validated_sandbox'] ?? false) !== true)
                || ($run->fake ? ! app()->environment('testing') : ! config('opshub.live_connectors_enabled')) || ! app(BackupArtifactAccess::class)->protection($run)) {
                throw new ConnectorFailure(ConnectorReason::NotConfigured);
            }
            if ($artifact->source_kind !== 'sftp' || ! in_array($artifact->verification_level, ['content_verified', 'restore_verified'], true)) {
                throw new \LogicException;
            }
            $key = app(BackupKeyResolver::class)->resolve($artifact->key_reference);
            $target->begin($drill);
            $inspection = new SftpBundleInspection($run->policy_snapshot['max_bytes'], $target);
            $store = app(PrivateObjectStore::class);
            $stream = $store->read($artifact->object_reference, $artifact->object_version);
            $nextCheck = 0;
            try {
                $result = app(SodiumEncryptedStream::class)->open($stream, $key, $artifact->context(), function (string $chunk) use ($drill, $artifact, $token, $inspection, &$nextCheck): void {
                    if (hrtime(true) >= $nextCheck) {
                        $this->guard($drill, $artifact, $token);
                        $nextCheck = hrtime(true) + 1_000_000_000;
                    }
                    $inspection->accept($chunk);
                }, $artifact->encrypted_bytes, $run->policy_snapshot['max_seconds']);
            } finally {
                fclose($stream);
            }
            if ($result['bytes'] !== $artifact->bytes || $result['sha256'] !== $artifact->sha256 || $result['encrypted_bytes'] !== $artifact->encrypted_bytes
                || $result['encrypted_sha256'] !== $artifact->encrypted_sha256 || ! ManifestIdentity::equal($result['manifest'], $artifact->manifest)) {
                throw new \LogicException;
            }
            $inspection->finish($result['manifest']);
            $checks = $target->check($result['manifest']);
            if (($checks['files_restored'] ?? false) !== true || ($checks['file_hashes_match'] ?? false) !== true || ($checks['file_count'] ?? null) !== $result['manifest']['file_count']) {
                throw new \LogicException;
            }
            $checks = ['files_restored' => true, 'file_hashes_match' => true, 'file_count' => $checks['file_count'], 'database_restored' => false, 'application_health_proven' => false];
            $this->guard($drill, $artifact, $token);
            $state = 'passed';
            $reason = null;
            $evidence = ['isolated_target' => true, 'production_overwrite' => false, 'authenticated_final' => true, 'coverage_scopes' => ['files'], 'fake' => $run->fake];
        } catch (\Throwable $error) {
            $reason = $error instanceof ConnectorFailure ? $error->reason->value : 'RESTORE_CHECK_FAILED';
            $state = in_array($reason, ['NOT_CONFIGURED', 'AUTHORIZATION_EXPIRED', 'WRITE_PAUSED', 'RECONCILE_REQUIRED', 'NETWORK_ERROR'], true) ? 'unknown' : 'failed';
        } finally {
            if (is_string($key)) {
                sodium_memzero($key);
            }
            try {
                $target->discard();
                $evidence['workspace_discarded'] = true;
            } catch (\Throwable) {
                $state = 'unknown';
                $reason = 'ISOLATED_CLEANUP_UNKNOWN';
            }
        }
        app(BackupExecution::class)->locked($run->id, function ($current) use ($drill, $artifact, $token, $state, $reason, $checks, $evidence): void {
            $drill = $drill->fresh();
            if ($drill->lease_owner !== $token || ! $drill->leased_until?->isFuture()) {
                return;
            }
            if ($state === 'passed') {
                try {
                    $this->guard($drill, $artifact, $token);
                } catch (\Throwable) {
                    $state = 'unknown';
                    $reason = 'AUTHORIZATION_EXPIRED';
                }
            }
            $artifact = $artifact->fresh();
            $drill->update(['state' => $state, 'reason_code' => $reason, 'checks' => $checks, 'evidence' => $evidence, 'evidence_reference' => 'drill:'.$drill->id,
                'lease_owner' => null, 'leased_until' => null, 'completed_at' => now('UTC')]);
            $artifact->update(['restore_pending' => $state === 'unknown', 'version' => $artifact->version + 1]);
            if ($reason === 'INTEGRITY_FAILED') {
                $artifact->update(['state' => 'failed', 'reason_code' => 'INTEGRITY_FAILED']);
                $current->update(['state' => 'failed', 'integrity_status' => 'failed', 'reason_code' => 'INTEGRITY_FAILED']);
            }
            if ($state === 'passed') {
                $artifact->update(['verification_level' => 'restore_verified']);
                DB::table('backup_verifications')->insert(['backup_artifact_id' => $artifact->id, 'level' => 'restore_verified', 'state' => 'passed', 'evidence' => json_encode(['drill_id' => $drill->id, 'target_kind' => 'isolated', 'coverage_scopes' => ['files'], 'fake' => $drill->fake]), 'checked_at' => now('UTC')]);
                app(BackupVerification::class)->advanceGoods($current, $artifact);
                $gaps = array_diff($current->policy_snapshot['required_scopes'], ['files']);
                $current->update(['integrity_status' => 'restore_verified', 'state' => $gaps ? 'partial' : 'succeeded', 'reason_code' => $gaps ? 'COVERAGE_GAP' : null]);
            }
            app(AuditWriter::class)->write(Organization::findOrFail($current->organization_id), 'backup.restore_drill.finished', 'backup_restore_drill', $drill->id, $state,
                User::findOrFail($drill->operator_user_id), after: ['artifact_id' => $artifact->id, 'reason_code' => $reason, 'coverage_scopes' => $state === 'passed' ? ['files'] : []]);
        });
    }

    public function reconcile(Organization $org, User $actor, BackupRestoreDrill $drill, int $version): BackupRestoreDrill
    {
        $artifact = BackupArtifact::findOrFail($drill->backup_artifact_id);

        return app(BackupExecution::class)->locked($artifact->backup_run_id, function () use ($org, $actor, $drill, $artifact, $version): BackupRestoreDrill {
            $this->require($org, $actor->fresh(), $artifact);
            $drill = $drill->fresh();
            abort_unless($drill->organization_id === $org->id && $drill->version === $version && in_array($drill->state, ['unknown', 'running'], true) && ! $drill->leased_until?->isFuture(), 409);
            // No automatic restoration retry/green after crash; authoritative isolated cleanup only.
            $target = app()->bound(RestoreTarget::class) ? app(RestoreTarget::class) : new UnconfiguredRestoreTarget;
            $facts = $target->inspect($drill);
            abort_unless(($facts['fake'] ?? null) === $drill->fake && ($facts['isolated'] ?? false) === true && ($facts['production'] ?? true) === false
                && ($drill->fake ? app()->environment('testing') : config('opshub.live_connectors_enabled') && ($facts['validated_sandbox'] ?? false) === true)
                && $target->reconcile($drill), 409, 'Authorized target cleanup evidence is required.');
            $drill->update(['state' => 'failed', 'reason_code' => 'DRILL_INTERRUPTED', 'version' => $version + 1, 'completed_at' => now('UTC')]);
            $artifact->fresh()->update(['restore_pending' => false, 'version' => $artifact->fresh()->version + 1]);
            app(AuditWriter::class)->write($org, 'backup.restore_drill.reconciled', 'backup_restore_drill', $drill->id, 'failed', $actor);

            return $drill;
        });
    }

    public function recordManual(Organization $org, User $actor, BackupRestoreDrill $drill, int $version, array $checks, string $evidence): BackupRestoreDrill
    {
        abort_unless(count($checks) === 4 && array_diff(array_keys($checks), ['target_isolated', 'production_overwrite', 'files_passed', 'database_passed']) === []
            && count(array_filter($checks, 'is_bool')) === 4, 422);
        abort_unless(preg_match('/^evidence:[a-zA-Z0-9_.-]{1,100}$/D', $evidence) && ($checks['target_isolated'] ?? false) === true && ($checks['production_overwrite'] ?? true) === false, 422);
        $artifact = BackupArtifact::findOrFail($drill->backup_artifact_id);

        return app(BackupExecution::class)->locked($artifact->backup_run_id, function () use ($org, $actor, $drill, $artifact, $version, $checks, $evidence): BackupRestoreDrill {
            $this->require($org, $actor->fresh(), $artifact);
            $drill = $drill->fresh();
            abort_unless($drill->organization_id === $org->id && $drill->version === $version && $drill->state === 'manual_required', 409);
            $drill->update(['state' => 'manual_recorded', 'reason_code' => 'LIVE_UNVERIFIED', 'checks' => $checks, 'evidence_reference' => $evidence, 'completed_at' => now('UTC'), 'version' => $version + 1]);
            $artifact->fresh()->update(['restore_pending' => false, 'version' => $artifact->fresh()->version + 1]);
            app(AuditWriter::class)->write($org, 'backup.restore_drill.manual_recorded', 'backup_restore_drill', $drill->id, 'live_unverified', $actor, after: ['evidence_reference' => $evidence]);

            return $drill;
        });
    }

    private function require(Organization $org, User $actor, BackupArtifact $artifact): void
    {
        app(BackupArtifactAccess::class)->require($org, $actor, $artifact, true);
        app(OrganizationAuthorizationService::class)->require($actor, $org, 'backup.restore_drill');
        abort_unless(app(ManagementAuthorizationService::class)->allows($org, 'hosting_account', $artifact->hosting_account_id, 'observe'), 403);
    }

    private function guard(BackupRestoreDrill $drill, BackupArtifact $artifact, string $token): void
    {
        app(BackupExecution::class)->locked($artifact->backup_run_id, function () use ($drill, $artifact, $token): void {
            $drill = $drill->fresh();
            $artifact = $artifact->fresh();
            if ($drill->lease_owner !== $token || ! $drill->leased_until?->isFuture() || $drill->state !== 'running' || $artifact->state !== 'verified') {
                throw new ConnectorFailure(ConnectorReason::ReconcileRequired);
            }
            try {
                $this->require(Organization::findOrFail($drill->organization_id), User::findOrFail($drill->operator_user_id), $artifact);
            } catch (\Throwable) {
                throw new ConnectorFailure(ConnectorReason::AuthorizationExpired);
            }
            if (app(BackupPolicies::class)->paused($drill->organization_id)) {
                throw new ConnectorFailure(ConnectorReason::WritePaused);
            }
            $drill->update(['leased_until' => now('UTC')->addSeconds(90)]);
        });
    }
}
