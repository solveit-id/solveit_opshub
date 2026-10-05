<?php

namespace App\Application\Backups;

use App\Application\Registry\ManagementAuthorizationService;
use App\Application\TelegramNotifications\OutboxWriter;
use App\Infrastructure\Backup\BackupKeyResolver;
use App\Infrastructure\Backup\ManifestIdentity;
use App\Infrastructure\Backup\PrivateObjectStore;
use App\Infrastructure\Backup\SftpBundleInspection;
use App\Infrastructure\Backup\SodiumEncryptedStream;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Models\BackupArtifact;
use App\Models\BackupRun;
use App\Models\HostingAccount;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BackupVerification
{
    public const LEVELS = ['none' => 0, 'transport_verified' => 1, 'content_verified' => 2, 'restore_verified' => 3];

    public function __construct(private BackupExecution $execution, private PrivateObjectStore $store, private BackupKeyResolver $keys, private SodiumEncryptedStream $cipher) {}

    public function execute(int $id, bool $manual = false): void
    {
        $claim = $this->execution->locked($id, function (BackupRun $run) use ($manual): ?array {
            $artifact = BackupArtifact::where('backup_run_id', $run->id)->first();
            $adopt = $manual && $artifact && $artifact->manifest === null && in_array($artifact->state, ['uploading', 'failed'], true);
            if (! $artifact || ($artifact->state !== 'stored' && ! $adopt) || ($run->state !== 'verifying' && ! ($manual && $run->state === 'reconcile_required')) || $run->leased_until?->isFuture()) {
                return null;
            }
            try {
                $this->requireRead($run);
            } catch (\Throwable) {
                $run->update(['reason_code' => 'AUTHORIZATION_EXPIRED', 'state' => 'reconcile_required']);

                return null;
            }
            if (! $this->execution->available($run)) {
                return null;
            }
            $token = $this->execution->claim($run, 'integrity_verification', 'verifying');

            return [$run, $artifact, $token, $adopt];
        });
        if (! $claim) {
            return;
        }
        [$run, $artifact, $token, $adopt] = $claim;
        $key = null;
        try {
            $this->protection($run);
            $key = $this->keys->resolve($artifact->key_reference);
            $metadata = $this->store->metadata($artifact->object_reference, $artifact->object_version);
            $this->metadata($artifact, $metadata, $adopt);
            $inspection = $artifact->source_kind === 'sftp' ? new SftpBundleInspection($run->policy_snapshot['max_bytes']) : null;
            $stream = $this->store->read($artifact->object_reference, $artifact->object_version);
            $nextCheck = 0;
            try {
                $result = $this->cipher->open($stream, $key, $artifact->context(), function (string $chunk) use ($inspection, $run, $token, &$nextCheck): void {
                    if (hrtime(true) >= $nextCheck) {
                        $this->execution->locked($run->id, function (BackupRun $current) use ($token): void {
                            if (! $this->execution->owns($current, $token)) {
                                throw new ConnectorFailure(ConnectorReason::ReconcileRequired);
                            }
                            $this->requireRead($current);
                            $current->update(['leased_until' => now('UTC')->addSeconds(90)]);
                        });
                        $nextCheck = hrtime(true) + 1_000_000_000;
                    }
                    $inspection?->accept($chunk);
                }, $run->preflight_evidence['reserved_bytes'] ?? 0, $run->policy_snapshot['max_seconds']);
            } finally {
                fclose($stream);
            }
            if ((! $adopt && ($result['bytes'] !== $artifact->bytes || $result['sha256'] !== $artifact->sha256 || $result['encrypted_bytes'] !== $artifact->encrypted_bytes
                || $result['encrypted_sha256'] !== $artifact->encrypted_sha256 || ! ManifestIdentity::equal($result['manifest'], $artifact->manifest)))
                || $result['encrypted_sha256'] !== $metadata['sha256'] || $result['encrypted_bytes'] !== $metadata['bytes']
                || ($result['manifest']['fake'] ?? null) !== $run->fake || ($result['manifest']['run_reference'] ?? null) !== $run->run_reference
                || ($result['manifest']['source_kind'] ?? null) !== $artifact->source_kind || $result['bytes'] > $run->policy_snapshot['max_bytes']
                || $result['encrypted_bytes'] > ($run->preflight_evidence['reserved_bytes'] ?? 0)) {
                throw new ConnectorFailure(ConnectorReason::IntegrityFailed);
            }
            $level = 'transport_verified';
            $scopes = [];
            if ($inspection) {
                $inspection->finish($result['manifest']);
                $level = 'content_verified';
                // Empty approved scope is only meaningful if at least one included directory actually existed.
                $scopes = ($result['manifest']['file_count'] > 0 || ! empty($result['manifest']['directories'])) ? ['files'] : [];
            } elseif (($result['manifest']['strategy'] ?? null) === 'cpanel_full_account' && ($result['manifest']['source_completion_proven'] ?? false) === true
                && in_array('full_account', $result['manifest']['reported_scopes'] ?? [], true)) {
                $scopes = ['full_account']; // Opaque archive transport does not prove files/DB semantic coverage.
            }
            $this->execution->locked($run->id, function (BackupRun $current) use ($artifact, $token, $level, $scopes, $result): void {
                if (! $this->execution->owns($current, $token)) {
                    return;
                }
                $this->requireRead($current);
                $artifact->update(['state' => 'verified', 'verification_level' => $level, 'coverage_scopes' => $scopes, 'verified_at' => now('UTC'), 'reason_code' => null,
                    'bytes' => $result['bytes'], 'sha256' => $result['sha256'], 'encrypted_bytes' => $result['encrypted_bytes'],
                    'encrypted_sha256' => $result['encrypted_sha256'], 'manifest' => $result['manifest']]);
                $this->record($artifact, 'transport_verified', 'passed', null, ['private_object' => true, 'independent' => true, 'ciphertext_checksum' => true, 'authenticated_final' => true]);
                if ($level === 'content_verified') {
                    $this->record($artifact, $level, 'passed', null, ['file_count' => $current->transferred_files, 'manifest_matches' => true]);
                }
                $meetsLevel = self::LEVELS[$level] >= self::LEVELS[$current->policy_snapshot['verification']];
                $gaps = array_values(array_diff($current->policy_snapshot['required_scopes'], $scopes));
                $reason = ! $meetsLevel ? 'VERIFICATION_LEVEL_REQUIRED' : ($gaps ? 'COVERAGE_GAP' : null);
                if (! $meetsLevel) {
                    $this->record($artifact, $current->policy_snapshot['verification'], 'unsupported', 'VERIFICATION_LEVEL_REQUIRED', ['native_content_or_drill_required' => true]);
                }
                if ($meetsLevel) {
                    $this->advanceGoods($current, $artifact);
                }
                $this->execution->finish($current, $token, ['state' => $reason ? 'partial' : 'succeeded', 'reason_code' => $reason,
                    'transfer_status' => 'independent_verified', 'integrity_status' => $level, 'completed_at' => now('UTC')]);
                app(OutboxWriter::class)->record(Organization::findOrFail($current->organization_id), $reason ? 'backup.partial' : 'backup.verified', 'backup_run', $current->id, $current->attempts,
                    ['route' => 'owner', 'severity' => $reason ? 'warning' : 'info', 'impacted_project_ids' => $current->impacted_project_ids]);
            });
        } catch (\Throwable $error) {
            $reason = $error instanceof ConnectorFailure ? $error->reason->value : 'INTEGRITY_FAILED';
            $unknown = in_array($reason, ['NOT_CONFIGURED', 'NETWORK_ERROR', 'RECONCILE_REQUIRED', 'AUTHORIZATION_EXPIRED'], true);
            $this->execution->locked($run->id, function (BackupRun $current) use ($token, $artifact, $reason, $unknown): void {
                if (! $this->execution->owns($current, $token)) {
                    return;
                }
                $artifact->update(['state' => $unknown ? ($artifact->manifest === null ? 'uploading' : 'stored') : 'failed', 'reason_code' => $reason]);
                $this->record($artifact, 'transport_verified', $unknown ? 'unknown' : 'failed', $reason, ['authenticated_final' => false, 'last_good_unchanged' => true]);
                $this->execution->finish($current, $token, ['state' => $unknown ? 'reconcile_required' : 'failed', 'reason_code' => $reason,
                    'integrity_status' => $unknown ? 'unknown' : 'failed', 'transfer_status' => 'stored_unverified', 'completed_at' => $unknown ? null : now('UTC')]);
                app(OutboxWriter::class)->record(Organization::findOrFail($current->organization_id), 'backup.failed', 'backup_run', $current->id, $current->attempts,
                    ['route' => 'owner', 'severity' => 'warning', 'reason_code' => $reason, 'impacted_project_ids' => $current->impacted_project_ids]);
            });
        } finally {
            if (is_string($key)) {
                sodium_memzero($key);
            }
        }
    }

    public function advanceGoods(BackupRun $run, BackupArtifact $artifact): void
    {
        foreach (array_intersect($run->policy_snapshot['required_scopes'], $artifact->coverage_scopes ?? []) as $scope) {
            $old = DB::table('backup_scope_goods')->where('hosting_account_id', $run->hosting_account_id)->where('scope', $scope)->lockForUpdate()->first();
            if ($old && $old->source_observed_at >= $artifact->source_observed_at->format('Y-m-d H:i:s.u')) {
                continue;
            }
            DB::table('backup_scope_goods')->updateOrInsert(['hosting_account_id' => $run->hosting_account_id, 'scope' => $scope],
                ['organization_id' => $run->organization_id, 'backup_artifact_id' => $artifact->id, 'source_observed_at' => $artifact->source_observed_at,
                    'verified_at' => now('UTC')]);
        }
    }

    public function requireRead(BackupRun $run): void
    {
        try {
            $org = Organization::findOrFail($run->organization_id);
            $actor = User::findOrFail($run->actor_user_id);
            app(BackupRuns::class)->requireRead($org, $actor, $run);
            app(BackupRuns::class)->requireActor($org, $actor, HostingAccount::findOrFail($run->hosting_account_id));
            if (! app(ManagementAuthorizationService::class)->allows($org, 'hosting_account', $run->hosting_account_id, 'observe')) {
                throw new ConnectorFailure(ConnectorReason::AuthorizationExpired);
            }
        } catch (\Throwable) {
            throw new ConnectorFailure(ConnectorReason::AuthorizationExpired);
        }
    }

    private function protection(BackupRun $run): void
    {
        if (! app()->runningInConsole() || $run->fake !== $this->store->fake() || ($run->fake ? ! app()->environment('testing') : ! config('opshub.live_connectors_enabled'))) {
            throw new ConnectorFailure(ConnectorReason::NotConfigured);
        }
        foreach (['configured', 'private', 'independent', 'transport_protected'] as $guard) {
            if (($this->store->protection($run)[$guard] ?? false) !== true) {
                throw new ConnectorFailure(ConnectorReason::NotConfigured);
            }
        }
    }

    private function metadata(BackupArtifact $artifact, array $metadata, bool $adopt): void
    {
        foreach (['private', 'independent', 'transport_protected'] as $guard) {
            if (($metadata[$guard] ?? false) !== true) {
                throw new ConnectorFailure(ConnectorReason::NotConfigured);
            }
        }
        if ((! $adopt && (($metadata['bytes'] ?? null) !== $artifact->encrypted_bytes || ($metadata['sha256'] ?? null) !== $artifact->encrypted_sha256))
            || ($metadata['version'] ?? null) !== $artifact->object_version || ($metadata['fake'] ?? null) !== $artifact->fake) {
            throw new ConnectorFailure(ConnectorReason::IntegrityFailed);
        }
    }

    private function record(BackupArtifact $artifact, string $level, string $state, ?string $reason, array $evidence): void
    {
        DB::table('backup_verifications')->insert(['backup_artifact_id' => $artifact->id, 'level' => $level, 'state' => $state, 'reason_code' => $reason,
            'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'checked_at' => now('UTC')]);
    }
}
