<?php

namespace App\Application\Backups;

use App\Application\IdentityAccess\ProjectAccess;
use App\Models\BackupPolicy;
use App\Models\BackupRetentionReport;
use App\Models\HostingAccount;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BackupScheduler
{
    public function tick(): int
    {
        $count = 0;
        foreach (BackupPolicy::where('enabled', true)->cursor() as $policy) {
            $local = CarbonImmutable::now($policy->configuration['timezone']);
            $slot = $this->slot($policy, $local);
            if ($slot->isFuture()) {
                $slot = $this->slot($policy, $local->subDay());
            }
            if ($slot->lessThan($policy->approved_at)) {
                continue;
            }
            $count += DB::transaction(function () use ($policy, $slot): int {
                $org = Organization::whereKey($policy->organization_id)->lockForUpdate()->firstOrFail();
                HostingAccount::whereKey($policy->hosting_account_id)->lockForUpdate()->firstOrFail();
                $current = $policy->fresh();
                if (! $current->enabled || $current->version !== $policy->version || app(BackupPolicies::class)->paused($org->id)) {
                    return 0;
                }
                $day = $slot->format('Y-m-d');
                if (DB::table('backup_schedule_slots')->where('backup_policy_id', $policy->id)->where('local_day', $day)->exists()) {
                    return 0;
                }
                $state = 'queued';
                $reason = null;
                $run = null;
                try {
                    $actor = User::findOrFail($policy->approved_by);
                    abort_unless(app(ProjectAccess::class)->owner($actor, $org), 403);
                    $run = app(BackupRuns::class)->enqueue($org, $actor, $policy, $policy->version, 'schedule-'.$day);
                } catch (HttpException $error) {
                    $state = 'blocked';
                    $reason = $error->getStatusCode() === 409 ? 'ACCOUNT_BUSY' : 'AUTHORIZATION_EXPIRED';
                } catch (AuthorizationException) {
                    $state = 'blocked';
                    $reason = 'AUTHORIZATION_EXPIRED';
                }
                DB::table('backup_schedule_slots')->insert(['backup_policy_id' => $policy->id, 'local_day' => $day, 'scheduled_at' => $slot->utc()->format('Y-m-d H:i:s.u'),
                    'backup_run_id' => $run?->id, 'state' => $state, 'reason_code' => $reason]);

                return $run ? 1 : 0;
            });
        }

        return $count;
    }

    public function retention(): int
    {
        $count = 0;
        foreach (BackupPolicy::where('enabled', true)->cursor() as $policy) {
            if (app(BackupPolicies::class)->paused($policy->organization_id)) {
                continue;
            }
            $count += DB::transaction(function () use ($policy): int {
                $org = Organization::whereKey($policy->organization_id)->lockForUpdate()->firstOrFail();
                $key = $policy->id.':'.$policy->version.':'.now($policy->configuration['timezone'])->format('Y-m-d');
                if (BackupRetentionReport::where('schedule_key', $key)->exists()) {
                    return 0;
                }
                $actor = User::findOrFail($policy->approved_by);
                try {
                    $report = app(BackupRetention::class)->dryRun($org, $actor, $policy);
                    $report->update(['schedule_key' => $key]);
                    app(BackupRetention::class)->enqueue($org, $actor, $report);
                } catch (HttpException|\Illuminate\Auth\Access\AuthorizationException) {
                    return 0;
                }

                return 1;
            });
        }

        return $count;
    }

    private function slot(BackupPolicy $policy, CarbonImmutable $day): CarbonImmutable
    {
        [$hour, $minute] = explode(':', $policy->configuration['daily_at']);
        $jitter = hexdec(substr(hash('sha256', $policy->hosting_account_id.':'.$day->format('Y-m-d')), 0, 8)) % ($policy->configuration['jitter_minutes'] + 1);

        return $day->startOfDay()->setTime((int) $hour, (int) $minute)->addMinutes($jitter);
    }
}
