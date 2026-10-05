<?php

namespace App\Application\Backups;

use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Models\BackupRestoreDrill;
use App\Models\BackupRun;
use App\Models\HostingAccount;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackupExecution
{
    public function locked(int $id, \Closure $operation): mixed
    {
        return DB::transaction(function () use ($id, $operation): mixed {
            $reference = BackupRun::findOrFail($id);
            DB::table('backup_execution_controls')->where('id', 1)->lockForUpdate()->first();
            Organization::whereKey($reference->organization_id)->lockForUpdate()->firstOrFail();
            HostingAccount::whereKey($reference->hosting_account_id)->lockForUpdate()->firstOrFail();

            return $operation(BackupRun::whereKey($id)->lockForUpdate()->firstOrFail());
        });
    }

    public function available(BackupRun $run): bool
    {
        // Current locking read avoids an older InnoDB snapshot after waiting for the global mutex.
        $heavy = BackupRun::where('id', '!=', $run->id)->whereNotNull('active_account_id')->where(function ($query): void {
            $query->whereIn('state', ['awaiting_source', 'transferring', 'verifying'])->orWhere(function ($query): void {
                $query->where('state', 'reconcile_required')->whereIn('id', DB::table('backup_source_operations')->whereIn('state', ['requesting', 'accepted', 'uncertain', 'stable', 'retrieved'])->select('backup_run_id'));
            });
        })->lockForUpdate()->get(['id'])->count();

        $drills = BackupRestoreDrill::whereIn('state', ['running', 'unknown'])->lockForUpdate()->get(['id'])->count();

        return $heavy + $drills < max(1, min(10, DB::table('backup_execution_controls')->where('id', 1)->value('maximum_parallel')));
    }

    public function claim(BackupRun $run, string $mode, string $state): string
    {
        $token = (string) Str::uuid();
        $run->update(['state' => $state, 'lease_owner' => $token, 'leased_until' => now('UTC')->addSeconds(90), 'attempts' => $run->attempts + 1, 'source_next_at' => null]);
        DB::table('backup_run_attempts')->insert(['backup_run_id' => $run->id, 'number' => $run->attempts, 'mode' => $mode, 'state' => 'claimed', 'started_at' => now('UTC')]);

        return $token;
    }

    public function owns(BackupRun $run, string $token): bool
    {
        return $run->lease_owner === $token && $run->leased_until?->isFuture();
    }

    public function finish(BackupRun $run, string $token, array $changes): bool
    {
        if (! $this->owns($run, $token)) {
            return false;
        }
        $run->update([...$changes, 'lease_owner' => null, 'leased_until' => null]);
        DB::table('backup_run_attempts')->where('backup_run_id', $run->id)->where('number', $run->attempts)
            ->update(['state' => 'finished', 'reason_code' => $changes['reason_code'] ?? null, 'completed_at' => now('UTC')]);

        return true;
    }

    public function consumer(BackupRun $run, string $token, bool $fake, \Closure $consume): \Closure
    {
        $nextCheck = 0;

        return function (string $chunk) use ($run, $token, $fake, $consume, &$nextCheck): void {
            if (strlen($chunk) > 65536) {
                throw new ConnectorFailure(ConnectorReason::LimitExceeded);
            }
            if (hrtime(true) >= $nextCheck) {
                $this->locked($run->id, function (BackupRun $current) use ($token, $fake): void {
                    if (! $this->owns($current, $token)) {
                        throw new ConnectorFailure(ConnectorReason::ReconcileRequired);
                    }
                    $reason = app(BackupRuns::class)->eligibility($current, $fake);
                    if ($reason !== null) {
                        throw new ConnectorFailure($reason === 'WRITE_PAUSED' ? ConnectorReason::WritePaused : ConnectorReason::AuthorizationExpired);
                    }
                    $current->update(['leased_until' => now('UTC')->addSeconds(90)]);
                });
                $nextCheck = hrtime(true) + 1_000_000_000;
            }
            $consume($chunk);
        };
    }
}
