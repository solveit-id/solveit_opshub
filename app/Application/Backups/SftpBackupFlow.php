<?php

namespace App\Application\Backups;

use App\Application\Connectors\CapabilityRecorder;
use App\Application\TelegramNotifications\OutboxWriter;
use App\Infrastructure\Backup\BackupSink;
use App\Infrastructure\Backup\SftpFileBundle;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorResult;
use App\Jobs\RunSftpBackup;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Models\Connector;
use App\Models\Organization;
use Carbon\CarbonImmutable;

class SftpBackupFlow
{
    public function __construct(private BackupExecution $execution, private SftpFileBundle $source, private BackupSink $sink) {}

    public function execute(int $id): void
    {
        $claim = $this->execution->locked($id, function (BackupRun $run): ?array {
            if ($run->state !== 'ready' || $run->leased_until?->isFuture() || $run->source_next_at?->isFuture()) {
                return null; // Partial/crashed transfer requires storage reconciliation; never overwrite or auto restart.
            }
            $connector = Connector::findOrFail(BackupPolicy::findOrFail($run->backup_policy_id)->connector_id);
            if ($connector->kind !== 'sftp') {
                return null;
            }
            $reason = app(BackupRuns::class)->eligibility($run, $this->source->fake());
            if (! $this->sink->available() || $this->sink->fake() !== $this->source->fake() || $run->fake !== $this->source->fake()) {
                $reason ??= 'NOT_CONFIGURED';
            }
            $observed = $run->preflight_evidence['observed_at'] ?? null;
            if (! $observed || CarbonImmutable::parse($observed)->isFuture() || CarbonImmutable::parse($observed)->lessThan(now('UTC')->subMinutes(5))) {
                $reason ??= 'CAPACITY_UNKNOWN';
            }
            if ($reason) {
                $run->update(['state' => 'blocked', 'reason_code' => $reason, 'completed_at' => now('UTC')]);

                return null;
            }
            if (! $this->execution->available($run)) {
                $run->update(['source_next_at' => now('UTC')->addSeconds(30)]);
                RunSftpBackup::dispatch($run->id)->onConnection('database')->onQueue('backup')->delay(now('UTC')->addSeconds(30));

                return null;
            }
            $token = $this->execution->claim($run, 'sftp_transfer', 'transferring');
            $run->update(['source_status' => 'reading_files', 'transfer_status' => 'in_progress']);

            return [$run, $connector, $token];
        });
        if (! $claim) {
            return;
        }
        [$run, $connector, $token] = $claim;
        $manifest = [];
        try {
            $receipt = $this->sink->receive($run, function (\Closure $consume) use ($run, $connector, $token, &$manifest): array {
                return $this->source->stream($connector->snapshot(), $run->policy_snapshot, $run->run_reference,
                    $this->execution->consumer($run, $token, $run->fake, $consume), $manifest);
            });
            $this->execution->locked($run->id, function (BackupRun $current) use ($token, $manifest, $receipt): void {
                if (! $this->execution->owns($current, $token)) {
                    return;
                }
                $reason = app(BackupRuns::class)->eligibility($current, $this->source->fake());
                if ($receipt->fake !== $current->fake || ! $receipt->independent || $receipt->sha256 !== ($manifest['sha256'] ?? null)
                    || $receipt->bytes !== ($manifest['bundle_bytes'] ?? null) || $receipt->bytes > ($current->preflight_evidence['reserved_bytes'] ?? 0)) {
                    $reason ??= 'INTEGRITY_FAILED';
                }
                $this->execution->finish($current, $token, ['state' => $reason ? 'reconcile_required' : 'verifying', 'reason_code' => $reason,
                    'source_status' => $reason ? 'partial' : 'files_read', 'transfer_status' => $reason ? 'unverified' : 'stored_unverified',
                    'transfer_manifest' => $manifest, 'transferred_bytes' => $manifest['bytes'] ?? 0, 'transferred_files' => $manifest['file_count'] ?? 0,
                    'transfer_errors' => count($manifest['errors'] ?? [])]);
            });
        } catch (\Throwable $error) {
            $this->execution->locked($run->id, function (BackupRun $current) use ($token, $manifest, $error, $connector): void {
                $reason = $error instanceof ConnectorFailure ? $error->reason->value : 'NETWORK_ERROR';
                if (! $this->execution->finish($current, $token, ['state' => 'reconcile_required', 'source_status' => 'partial', 'transfer_status' => 'partial',
                    'reason_code' => $reason, 'transfer_manifest' => $manifest, 'transferred_bytes' => $manifest['bytes'] ?? 0,
                    'transferred_files' => $manifest['file_count'] ?? 0, 'transfer_errors' => max(1, count($manifest['errors'] ?? []))])) {
                    return;
                }
                if (in_array($reason, ['AUTH_FAILED', 'PERMISSION_DENIED'], true) && $connector->fresh()->version === $current->connector_version) {
                    app(CapabilityRecorder::class)->record($connector, $current->connector_version, 'backup', new ConnectorResult('permission_denied', 'file_backup', $reason, fake: $current->fake));
                }
                app(OutboxWriter::class)->record(Organization::findOrFail($current->organization_id), 'backup.failed', 'backup_run', $current->id, $current->attempts,
                    ['route' => 'owner', 'severity' => 'warning', 'reason_code' => $reason, 'impacted_project_ids' => $current->impacted_project_ids]);
            });
        }
    }
}
