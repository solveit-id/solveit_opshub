<?php

namespace App\Infrastructure\Backup;

use App\Application\Backups\BackupExecution;
use App\Application\Backups\BackupRuns;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Models\BackupArtifact;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Models\Connector;
use App\Models\HostingAccount;
use Illuminate\Support\Str;

class EncryptedBackupSink implements BackupSink
{
    public function __construct(private PrivateObjectStore $store, private BackupKeyResolver $keys, private SodiumEncryptedStream $cipher, private BackupExecution $execution) {}

    public function fake(): bool
    {
        return $this->store->fake();
    }

    public function available(): bool
    {
        return ! ($this->store instanceof UnconfiguredObjectStore) && ($this->fake() ? app()->environment('testing') : config('opshub.live_connectors_enabled'));
    }

    public function receive(BackupRun $run, \Closure $producer): TransferReceipt
    {
        if (! app()->runningInConsole() || ! $this->available() || $run->fake !== $this->fake()) {
            throw new ConnectorFailure(ConnectorReason::NotConfigured);
        }
        $protection = $this->store->protection($run);
        foreach (['configured', 'private', 'independent', 'transport_protected'] as $guard) {
            if (($protection[$guard] ?? false) !== true) {
                throw new ConnectorFailure(ConnectorReason::NotConfigured);
            }
        }
        $reference = $this->keys->reference($run->organization_id, $run->policy_snapshot['destination_reference']);
        if (! $reference || ! preg_match('/^(?:vault:[a-zA-Z0-9_\/-]{1,180}|env:OPSHUB_BACKUP_KEY_[A-Z0-9_]{3,100})$/D', $reference)) {
            throw new ConnectorFailure(ConnectorReason::NotConfigured);
        }
        $key = $this->keys->resolve($reference);
        try {
            $artifact = $this->execution->locked($run->id, function (BackupRun $current) use ($run, $reference): BackupArtifact {
                if (! $this->execution->owns($current, $run->lease_owner) || $current->state !== 'transferring'
                    || app(BackupRuns::class)->eligibility($current, $this->fake()) !== null || BackupArtifact::where('backup_run_id', $current->id)->exists()) {
                    throw new ConnectorFailure(ConnectorReason::ReconcileRequired);
                }

                return BackupArtifact::create(['organization_id' => $current->organization_id, 'hosting_account_id' => $current->hosting_account_id,
                    'backup_run_id' => $current->id, 'artifact_reference' => (string) Str::uuid(), 'environment_ids' => HostingAccount::findOrFail($current->hosting_account_id)->asset->usages()->pluck('environment_id')->unique()->sort()->values()->all(),
                    'source_kind' => Connector::findOrFail(BackupPolicy::findOrFail($current->backup_policy_id)->connector_id)->kind,
                    'object_reference' => 'store:'.Str::uuid(), 'object_version' => (string) Str::uuid(), 'key_reference' => $reference,
                    'fake' => $current->fake, 'source_observed_at' => now('UTC')]);
            });
            $result = null;
            $metadata = $this->store->put($run, $artifact->object_reference, $artifact->object_version, function (\Closure $consume) use ($artifact, $run, $producer, &$result, $key): void {
                $boundProducer = function (\Closure $consumer) use ($artifact, $run, $producer): array {
                    return [...$producer($consumer), 'run_reference' => $run->run_reference, 'source_kind' => $artifact->source_kind, 'environment_ids' => $artifact->environment_ids];
                };
                $result = $this->cipher->seal($key, $artifact->context(), $boundProducer, $consume);
            });
            if (! $result || ($metadata['bytes'] ?? null) !== $result['encrypted_bytes'] || ($metadata['sha256'] ?? null) !== $result['encrypted_sha256']
                || ($metadata['fake'] ?? null) !== $run->fake || ($metadata['version'] ?? null) !== $artifact->object_version) {
                throw new ConnectorFailure(ConnectorReason::IntegrityFailed);
            }
            // A late worker may record sealed object identity, never promote run or integrity. Reconcile can inspect it.
            $this->execution->locked($run->id, function (BackupRun $current) use ($artifact, $result): void {
                $artifact->update(['state' => 'stored', 'bytes' => $result['bytes'], 'sha256' => $result['sha256'],
                    'encrypted_bytes' => $result['encrypted_bytes'], 'encrypted_sha256' => $result['encrypted_sha256'], 'manifest' => $result['manifest']]);
            });

            return new TransferReceipt($artifact->object_reference, $artifact->object_version, $result['bytes'], $result['sha256'], true, $this->fake());
        } catch (\Throwable $error) {
            if (isset($artifact)) {
                $artifact->update(['state' => 'failed', 'reason_code' => $error instanceof ConnectorFailure ? $error->reason->value : 'STORAGE_UNAVAILABLE']);
            }
            throw $error;
        } finally {
            if (is_string($key)) {
                sodium_memzero($key);
            }
        }
    }
}
