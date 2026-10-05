<?php

namespace App\Application\Backups;

use App\Application\ActivityEvidence\AuditWriter;
use App\Jobs\VerifyBackup;
use App\Models\BackupArtifact;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Models\BackupSourceOperation;
use App\Models\Connector;
use App\Models\HostingAccount;
use App\Models\Organization;
use App\Models\User;

class BackupReconciliation
{
    public function enqueue(Organization $org, User $actor, BackupRun $run): void
    {
        app(BackupRuns::class)->requireRead($org, $actor, $run);
        app(BackupRuns::class)->requireActor($org, $actor, HostingAccount::findOrFail($run->hosting_account_id));
        if (! BackupArtifact::where('backup_run_id', $run->id)->exists() && (BackupSourceOperation::where('backup_run_id', $run->id)->exists()
            || Connector::findOrFail(BackupPolicy::findOrFail($run->backup_policy_id)->connector_id)->kind === 'cpanel')) {
            app(CpanelBackupFlow::class)->enqueueReconcile($org, $actor, $run);

            return;
        }
        app(BackupExecution::class)->locked($run->id, function (BackupRun $current) use ($org, $actor): void {
            app(BackupRuns::class)->requireRead($org->fresh(), $actor->fresh(), $current);
            app(BackupRuns::class)->requireActor($org->fresh(), $actor->fresh(), HostingAccount::findOrFail($current->hosting_account_id));
            abort_unless(in_array($current->state, ['verifying', 'reconcile_required'], true), 409);
            abort_if($current->leased_until?->isFuture(), 409);
            abort_if($current->source_next_at?->isFuture(), 429);
            $artifact = BackupArtifact::where('backup_run_id', $current->id)->first();
            if (! $artifact) {
                // SFTP is read-only; absence of committed object intent proves this driver never called store.put.
                abort_if(BackupSourceOperation::where('backup_run_id', $current->id)->exists(), 409);
                $current->update(['state' => 'failed', 'reason_code' => 'NO_OBJECT_INTENT', 'lease_owner' => null, 'leased_until' => null, 'completed_at' => now('UTC')]);
            } else {
                $current->update(['actor_user_id' => $actor->id, 'source_next_at' => now('UTC')->addSeconds(30)]);
                VerifyBackup::dispatch($current->id, true)->onConnection('database')->onQueue('backup');
            }
            app(AuditWriter::class)->write($org, 'backup.reconcile.requested', 'backup_run', $current->id, $artifact ? 'queued' : 'no_object_intent', $actor);
        });
    }
}
