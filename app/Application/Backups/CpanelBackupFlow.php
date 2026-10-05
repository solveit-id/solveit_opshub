<?php

namespace App\Application\Backups;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\Connectors\CapabilityRecorder;
use App\Application\Registry\ManagementAuthorizationService;
use App\Application\TelegramNotifications\OutboxWriter;
use App\Infrastructure\Backup\BackupSink;
use App\Infrastructure\Backup\BackupWritePermit;
use App\Infrastructure\Backup\CpanelBackupSource;
use App\Infrastructure\Backup\SourceAcceptance;
use App\Infrastructure\Backup\SourceArtifact;
use App\Infrastructure\Backup\SourceObservation;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Infrastructure\Connectors\ConnectorResult;
use App\Jobs\PreflightBackup;
use App\Jobs\RunCpanelBackup;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Models\BackupSourceOperation;
use App\Models\Connector;
use App\Models\HostingAccount;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CpanelBackupFlow
{
    public function __construct(private BackupExecution $execution, private CpanelBackupSource $source, private BackupSink $sink) {}

    public function execute(int $id, bool $manual = false): void
    {
        $claim = $this->execution->locked($id, function (BackupRun $run) use ($manual): ?array {
            if (! in_array($run->state, ['ready', 'awaiting_source', 'reconcile_required'], true)
                || $run->leased_until?->isFuture() || (! $manual && $run->source_next_at?->isFuture())) {
                return null;
            }
            $connector = Connector::findOrFail(BackupPolicy::findOrFail($run->backup_policy_id)->connector_id);
            if ($connector->kind !== 'cpanel') {
                return null;
            }
            $operation = BackupSourceOperation::where('backup_run_id', $run->id)->first();
            if ($run->state === 'ready' && (! $operation || $operation->state === 'idle_proven')) {
                $reason = app(BackupRuns::class)->eligibility($run, $this->source->fake());
                if (! $this->source->configured() || ! $this->sink->available() || $this->sink->fake() !== $this->source->fake() || $run->fake !== $this->source->fake()) {
                    $reason ??= 'NOT_CONFIGURED';
                }
                $observed = $run->preflight_evidence['observed_at'] ?? null;
                if (! $observed || CarbonImmutable::parse($observed)->lessThan(now('UTC')->subMinutes(5))) {
                    $reason ??= 'CAPACITY_UNKNOWN';
                }
                if ($reason) {
                    $run->update(['state' => 'blocked', 'reason_code' => $reason, 'completed_at' => now('UTC')]);

                    return null;
                }
                if (! $this->execution->available($run)) {
                    $this->poll($run);

                    return null;
                }
                if (($operation?->request_count ?? 0) >= 3) {
                    $run->update(['state' => 'failed', 'reason_code' => 'LIMIT_EXCEEDED', 'completed_at' => now('UTC')]);

                    return null;
                }
                $token = $this->execution->claim($run, 'source_request', 'awaiting_source');
                $operation ??= new BackupSourceOperation(['backup_run_id' => $run->id, 'request_count' => 0]);
                $operation->fill(['request_count' => $operation->request_count + 1, 'state' => 'requesting', 'provider_reference' => null,
                    'started_at' => now('UTC'), 'deadline_at' => now('UTC')->addSeconds($run->policy_snapshot['max_seconds']),
                    'artifact_locator' => null, 'stable_since' => null, 'stable_fingerprint' => null])->save();
                DB::table('backup_source_requests')->insert(['backup_source_operation_id' => $operation->id, 'number' => $operation->request_count, 'state' => 'requesting', 'started_at' => now('UTC')]);
                $run->update(['source_status' => 'requesting']);
                app(OutboxWriter::class)->record(Organization::findOrFail($run->organization_id), 'backup.started', 'backup_run', $run->id, $operation->request_count,
                    ['route' => 'owner', 'severity' => 'info', 'impacted_project_ids' => $run->impacted_project_ids]);

                return [$run, $connector, $operation, $token, true];
            }
            if (! $operation) {
                // No committed source intent means preflight recovery; external request always follows intent commit.
                if (! app(BackupPolicies::class)->paused($run->organization_id)) {
                    $run->update(['state' => 'queued', 'lease_owner' => null, 'leased_until' => null]);
                    PreflightBackup::dispatch($run->id)->onConnection('database')->onQueue('backup');
                }

                return null;
            }
            if (! $this->canObserve($run, $connector)) {
                $run->update(['state' => 'reconcile_required', 'reason_code' => 'AUTHORIZATION_EXPIRED']);

                return null;
            }
            $token = $this->execution->claim($run, 'source_reconcile', $run->state === 'ready' ? 'reconcile_required' : $run->state);

            return [$run, $connector, $operation, $token, false];
        });
        if (! $claim) {
            return;
        }
        [$run, $connector, $operation, $token, $request] = $claim;
        if ($request) {
            try {
                $accepted = $this->source->request($run, $connector, BackupWritePermit::issue($run->id, $token));
            } catch (\Throwable) {
                $accepted = new SourceAcceptance('uncertain', null, ConnectorReason::Network, $this->source->fake());
            }
            $this->accept($run, $connector, $operation, $token, $accepted);

            return;
        }
        try {
            $observation = $this->source->observe($run, $connector, $operation->provider_reference);
        } catch (\Throwable) {
            $observation = new SourceObservation('unknown', reason: ConnectorReason::Network, fake: $this->source->fake());
        }
        $artifact = $this->observe($run, $operation, $token, $observation);
        if ($artifact) {
            $this->transfer($run, $connector, $operation, $token, $artifact);
        }
    }

    private function accept(BackupRun $run, Connector $connector, BackupSourceOperation $operation, string $token, SourceAcceptance $accepted): void
    {
        $this->execution->locked($run->id, function (BackupRun $current) use ($connector, $operation, $token, $accepted): void {
            if (! $this->execution->owns($current, $token)) {
                return;
            }
            $state = $accepted->fake === $current->fake ? $accepted->state : 'uncertain';
            $reason = $accepted->fake === $current->fake ? $accepted->reason?->value : 'RESPONSE_INVALID';
            $reference = $accepted->fake === $current->fake ? $accepted->providerReference : null;
            $operation->update(['state' => $state, 'provider_reference' => $reference]);
            DB::table('backup_source_requests')->where('backup_source_operation_id', $operation->id)->where('number', $operation->request_count)
                ->update(['state' => $state, 'provider_reference' => $reference, 'reason_code' => $reason, 'completed_at' => now('UTC')]);
            $next = $state === 'accepted' ? 'awaiting_source' : ($state === 'rejected' ? 'failed' : 'reconcile_required');
            // An accepted/uncertain remote write cannot become safely cancelled when local permission changes.
            if (app(BackupRuns::class)->eligibility($current, $this->source->fake()) !== null && $state !== 'rejected') {
                $next = 'reconcile_required';
                $reason = 'RECONCILE_REQUIRED';
            }
            $this->execution->finish($current, $token, ['state' => $next, 'source_status' => $state, 'reason_code' => $reason, 'completed_at' => $next === 'failed' ? now('UTC') : null]);
            if ($next === 'awaiting_source') {
                $this->poll($current);
            }
            if ($next === 'failed') {
                if (in_array($reason, ['AUTH_FAILED', 'PERMISSION_DENIED'], true) && $connector->fresh()->version === $current->connector_version) {
                    app(CapabilityRecorder::class)->record($connector, $current->connector_version, 'backup', new ConnectorResult('permission_denied', 'full_backup_trigger', $reason, fake: $current->fake));
                }
                $this->failureEvent($current, $reason);
            }
        });
    }

    private function observe(BackupRun $run, BackupSourceOperation $operation, string $token, SourceObservation $observation): ?SourceArtifact
    {
        return $this->execution->locked($run->id, function (BackupRun $current) use ($operation, $token, $observation): ?SourceArtifact {
            if (! $this->execution->owns($current, $token)) {
                return null;
            }
            if ($observation->fake !== $current->fake) {
                $observation = new SourceObservation('unknown', reason: ConnectorReason::ResponseInvalid, fake: $current->fake);
            }
            if ($observation->state === 'idle_proven') {
                $operation->update(['state' => 'idle_proven']);
                $paused = app(BackupPolicies::class)->paused($current->organization_id);
                $this->execution->finish($current, $token, ['state' => $paused ? 'cancelled' : 'queued', 'source_status' => 'idle_proven',
                    'reason_code' => $paused ? 'WRITE_PAUSED' : null, 'completed_at' => $paused ? now('UTC') : null, 'preflight_evidence' => null]);
                if (! $paused) {
                    PreflightBackup::dispatch($current->id)->onConnection('database')->onQueue('backup');
                }

                return null;
            }
            $artifact = $observation->artifact;
            if ($observation->state !== 'ready' || ! $artifact?->completionProven) {
                $unknown = $observation->state !== 'busy' || ! $operation->deadline_at->isFuture();
                $operation->update(['state' => $unknown ? 'uncertain' : 'accepted']);
                $this->execution->finish($current, $token, ['state' => $unknown ? 'reconcile_required' : 'awaiting_source',
                    'source_status' => $unknown ? 'unknown' : 'generating', 'reason_code' => $observation->reason?->value ?? ($unknown ? 'RECONCILE_REQUIRED' : 'ARTIFACT_INCOMPLETE')]);
                if (! $unknown) {
                    $this->poll($current);
                }

                return null;
            }
            if ($artifact->runReference !== $current->run_reference || ($operation->provider_reference !== null && $artifact->providerReference !== $operation->provider_reference)
                || $artifact->bytes > ($current->preflight_evidence['estimated_bytes'] ?? 0) || $artifact->bytes > $current->policy_snapshot['max_bytes']) {
                $this->execution->finish($current, $token, ['state' => 'reconcile_required', 'source_status' => 'unknown', 'reason_code' => 'ARTIFACT_INCOMPLETE']);

                return null;
            }
            $fingerprint = hash('sha256', json_encode([$artifact->locator, $artifact->bytes, $artifact->mtime], JSON_THROW_ON_ERROR));
            if ($operation->stable_fingerprint !== $fingerprint || ! $operation->stable_since || $operation->stable_since->greaterThan(now('UTC')->subSeconds(30))) {
                if ($operation->stable_fingerprint !== $fingerprint) {
                    $operation->update(['stable_fingerprint' => $fingerprint, 'stable_since' => now('UTC'), 'provider_reference' => $artifact->providerReference,
                        'artifact_locator' => get_object_vars($artifact)]);
                }
                $this->execution->finish($current, $token, ['state' => 'awaiting_source', 'source_status' => 'stabilizing', 'reason_code' => 'ARTIFACT_INCOMPLETE']);
                $this->poll($current);

                return null;
            }
            $reason = app(BackupRuns::class)->eligibility($current, $this->source->fake());
            if ($reason !== null || ! $this->sink->available() || $this->sink->fake() !== $current->fake) {
                $this->execution->finish($current, $token, ['state' => 'reconcile_required', 'reason_code' => $reason ?? 'NOT_CONFIGURED']);

                return null;
            }
            $operation->update(['state' => 'stable']);
            $current->update(['state' => 'transferring', 'source_status' => 'stable', 'transfer_status' => 'in_progress']);

            return $artifact;
        });
    }

    private function transfer(BackupRun $run, Connector $connector, BackupSourceOperation $operation, string $token, SourceArtifact $artifact): void
    {
        $run->refresh();
        try {
            $manifest = [];
            $receipt = $this->sink->receive($run, function (\Closure $consume) use ($run, $connector, $artifact, $token, &$manifest): array {
                $bytes = 0;
                $hash = hash_init('sha256');
                $consumer = $this->execution->consumer($run, $token, $run->fake, function (string $chunk) use ($consume, &$bytes, $hash, $artifact): void {
                    $bytes += strlen($chunk);
                    if ($bytes > $artifact->bytes) {
                        throw new ConnectorFailure(ConnectorReason::LimitExceeded);
                    }
                    hash_update($hash, $chunk);
                    $consume($chunk);
                });
                $manifest = $this->source->stream($run, $connector, $artifact, $consumer);
                if ($bytes !== $artifact->bytes || ($manifest['sha256'] ?? null) !== hash_final($hash)) {
                    throw new ConnectorFailure(ConnectorReason::ArtifactIncomplete);
                }

                return $manifest;
            });
            $this->execution->locked($run->id, function (BackupRun $current) use ($operation, $token, $receipt, $artifact, $manifest): void {
                if (! $this->execution->owns($current, $token)) {
                    return;
                }
                $valid = $receipt->fake === $current->fake && $receipt->independent && $receipt->bytes === $artifact->bytes && $receipt->sha256 === ($manifest['sha256'] ?? null);
                $reason = app(BackupRuns::class)->eligibility($current, $this->source->fake());
                if (! $valid || $reason) {
                    $this->execution->finish($current, $token, ['state' => 'reconcile_required', 'reason_code' => $reason ?? 'INTEGRITY_FAILED', 'transfer_status' => 'unverified']);

                    return;
                }
                $operation->update(['state' => 'retrieved']);
                $this->execution->finish($current, $token, ['state' => 'verifying', 'source_status' => 'retrieved', 'transfer_status' => 'stored_unverified', 'reason_code' => null]);
            });
        } catch (\Throwable $error) {
            $this->execution->locked($run->id, function (BackupRun $current) use ($token, $error): void {
                $this->execution->finish($current, $token, ['state' => 'reconcile_required', 'transfer_status' => 'partial',
                    'reason_code' => $error instanceof ConnectorFailure ? $error->reason->value : 'NETWORK_ERROR']);
            });
        }
    }

    private function canObserve(BackupRun $run, Connector $connector): bool
    {
        if ($run->fake !== $this->source->fake() || ($run->fake && ! app()->environment('testing')) || (! $run->fake && ! config('opshub.live_connectors_enabled')) || $connector->version !== $run->connector_version) {
            return false;
        }
        try {
            $org = Organization::findOrFail($run->organization_id);
            $actor = User::findOrFail($run->actor_user_id);
            app(BackupRuns::class)->requireRead($org, $actor, $run);
            app(BackupRuns::class)->requireActor($org, $actor, HostingAccount::findOrFail($run->hosting_account_id));

            return app(ManagementAuthorizationService::class)->allows($org, 'hosting_account', $run->hosting_account_id, 'observe');
        } catch (\Throwable) {
            return false;
        }
    }

    private function poll(BackupRun $run): void
    {
        $run->update(['source_next_at' => now('UTC')->addSeconds(30)]);
        RunCpanelBackup::dispatch($run->id)->onConnection('database')->onQueue('backup')->delay(now('UTC')->addSeconds(30));
    }

    private function failureEvent(BackupRun $run, ?string $reason): void
    {
        app(OutboxWriter::class)->record(Organization::findOrFail($run->organization_id), 'backup.failed', 'backup_run', $run->id, $run->attempts,
            ['route' => 'owner', 'severity' => 'warning', 'reason_code' => $reason, 'impacted_project_ids' => $run->impacted_project_ids]);
    }

    public function enqueueReconcile(Organization $org, User $actor, BackupRun $run): void
    {
        app(BackupRuns::class)->requireRead($org, $actor, $run);
        app(BackupRuns::class)->requireActor($org, $actor, HostingAccount::findOrFail($run->hosting_account_id));
        $this->execution->locked($run->id, function (BackupRun $current) use ($org, $actor): void {
            app(BackupRuns::class)->requireRead($org->fresh(), $actor->fresh(), $current);
            app(BackupRuns::class)->requireActor($org->fresh(), $actor->fresh(), HostingAccount::findOrFail($current->hosting_account_id));
            abort_unless(in_array($current->state, ['awaiting_source', 'reconcile_required'], true), 409);
            abort_if($current->source_next_at?->isFuture(), 429);
            $current->update(['actor_user_id' => $actor->id, 'source_next_at' => now('UTC')->addSeconds(30)]);
            RunCpanelBackup::dispatch($current->id, true)->onConnection('database')->onQueue('backup');
            app(AuditWriter::class)->write($org, 'backup.reconcile.requested', 'backup_run', $current->id, 'queued', $actor);
        });
    }
}
