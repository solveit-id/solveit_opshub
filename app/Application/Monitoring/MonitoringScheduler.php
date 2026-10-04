<?php

namespace App\Application\Monitoring;

use App\Application\PolicyScheduling\EffectiveMonitoringPolicyService;
use App\Application\PolicyScheduling\JobSlotService;
use App\Jobs\ProbeMonitor;
use App\Models\JobRun;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\PolicyAssignment;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MonitoringScheduler
{
    public function synchronize(Organization $org, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($org, $now): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $usageIds = [];
            foreach (Project::forOrganization($org)->where('lifecycle', 'active')->get() as $project) {
                $assignment = PolicyAssignment::forOrganization($org)->where('resource_type', 'project')->where('resource_id', $project->id)->where('is_active', true)->with('policyVersion')->latest('id')->first();
                if ($assignment === null) {
                    continue;
                }
                $configuration = app(EffectiveMonitoringPolicyService::class)->applyOverrides($assignment->policyVersion->configuration, $assignment->overrides ?? []);
                foreach ($project->assetUsages()->with(['asset', 'environment'])->get() as $usage) {
                    $environment = $usage->environment ?? $project->environments()->where('kind', 'production')->first();
                    if ($environment === null) {
                        continue;
                    }
                    $kinds = $usage->purpose === 'public_endpoint' && $usage->asset->kind === 'url' ? ['http', 'tls'] : ($usage->purpose === 'dns' && $usage->asset->kind === 'domain' ? ['dns'] : []);
                    foreach ($kinds as $kind) {
                        $setting = $configuration['checks'][$kind];
                        if (! $setting['enabled']) {
                            continue;
                        }
                        $setting['asset_version'] = $usage->asset->version;
                        $digest = hash('sha256', json_encode([$assignment->policy_version_id, $setting]));
                        $monitor = Monitor::firstOrCreate(['organization_id' => $org->id, 'asset_id' => $usage->asset_id, 'environment_kind' => $environment->kind === 'custom' ? 'custom:'.$environment->id : $environment->kind, 'kind' => $kind, 'configuration_digest' => $digest], [
                            'policy_version_id' => $assignment->policy_version_id, 'configuration' => $setting, 'interval_seconds' => $setting['interval_seconds'], 'next_due_at' => $now,
                        ]);
                        $monitor->update(['enabled' => true]);
                        DB::table('monitor_usages')->updateOrInsert(['monitor_id' => $monitor->id, 'project_id' => $project->id, 'environment_id' => $environment->id], ['active' => true]);
                        $usageIds[] = DB::table('monitor_usages')->where('monitor_id', $monitor->id)->where('project_id', $project->id)->where('environment_id', $environment->id)->value('id');
                    }
                }
            }
            DB::table('monitor_usages')->whereIn('monitor_id', Monitor::forOrganization($org)->select('id'))->whereNotIn('id', $usageIds)->update(['active' => false]);
            $inactive = Monitor::forOrganization($org)->whereDoesntHave('activeProjects')->pluck('id');
            Monitor::whereIn('id', $inactive)->update(['enabled' => false]);
            JobRun::forOrganization($org)->where('resource_type', 'monitor')->whereIn('resource_id', $inactive)->where('state', 'queued')->update(['state' => 'cancelled']);
        });
    }

    public function tick(Organization $org, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $this->synchronize($org, $now);
        $this->heartbeat($org->id, 'scheduler', $now);
        $count = 0;
        foreach (Monitor::forOrganization($org)->where('enabled', true)->where('next_due_at', '<=', $now->format('Y-m-d H:i:s'))->get() as $monitor) {
            $count += DB::transaction(function () use ($org, $monitor, $now): int {
                $locked = Monitor::whereKey($monitor->id)->lockForUpdate()->firstOrFail();
                if ($locked->next_due_at->greaterThan($now)) {
                    return 0;
                }
                $missed = max(0, intdiv($now->timestamp - $locked->next_due_at->timestamp, $locked->interval_seconds));
                $slot = $locked->next_due_at->addSeconds($missed * $locked->interval_seconds);
                JobRun::forOrganization($org)->where('resource_type', 'monitor')->where('resource_id', $locked->id)->where('state', 'queued')->where('scheduled_slot', '<', $slot->format('Y-m-d H:i:s'))->update(['state' => 'coalesced', 'last_error_code' => 'MISSED_SLOT']);
                $run = app(JobSlotService::class)->reserve($org, 'monitor.'.$locked->kind, $slot, 'monitor', $locked->id, $locked->policy_version_id);
                if ($run->queue_enqueued_at === null && $run->state === 'queued') {
                    $run->update(['queue_enqueued_at' => $now, 'missed_slots' => $missed]);
                    // The database queue insert shares this transaction, avoiding a dispatch-after-commit gap.
                    ProbeMonitor::dispatch($run->id)->onConnection('database')->onQueue(config('opshub.queue.probe'));
                }
                $locked->update(['next_due_at' => $slot->addSeconds($locked->interval_seconds)]);

                return 1;
            });
        }
        // A crashed worker is fenced by lease expiry; replay the same slot rather than inventing a failure.
        foreach (JobRun::forOrganization($org)->where('resource_type', 'monitor')->where('state', 'running')->where('leased_until', '<=', $now->format('Y-m-d H:i:s'))->get() as $run) {
            DB::transaction(function () use ($run, $now): void {
                $locked = JobRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($locked->state === 'running' && $locked->leased_until->lessThanOrEqualTo($now)) {
                    if ($locked->attempts >= 3) {
                        $locked->update(['state' => 'failed', 'last_error_code' => 'ATTEMPTS_EXHAUSTED']);

                        return;
                    }
                    $locked->update(['state' => 'queued', 'last_error_code' => 'LEASE_EXPIRED', 'queue_enqueued_at' => $now]);
                    ProbeMonitor::dispatch($locked->id)->onConnection('database')->onQueue(config('opshub.queue.probe'));
                }
            });
        }

        return $count;
    }

    public function heartbeat(int $organizationId, string $component, CarbonImmutable $at): void
    {
        DB::table('runtime_heartbeats')->updateOrInsert(['organization_id' => $organizationId, 'component' => $component], ['observed_at' => $at->utc()->format('Y-m-d H:i:s'), 'metadata' => json_encode(['source' => 'opshub_runtime'])]);
    }
}
