<?php

namespace App\Application\Monitoring;

use App\Application\TelegramNotifications\NotificationHealth;
use App\Models\JobRun;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RuntimeHealth
{
    public function snapshot(Organization $org, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $components = [];
        foreach (['scheduler', 'worker:probe'] as $name) {
            $heartbeat = DB::table('runtime_heartbeats')->where('organization_id', $org->id)->where('component', $name)->first();
            $components[$name] = ['state' => $heartbeat === null ? 'never_observed' : (CarbonImmutable::parse($heartbeat->observed_at, 'UTC')->addSeconds(180)->lessThanOrEqualTo($now) ? 'stale' : 'fresh'), 'last_observed_at' => $heartbeat ? CarbonImmutable::parse($heartbeat->observed_at, 'UTC')->toIso8601String() : null];
        }
        $queued = JobRun::forOrganization($org)->where('resource_type', 'monitor')->where('state', 'queued');
        $oldest = $queued->min('scheduled_slot');
        $components['queue'] = ['state' => $oldest === null ? 'idle' : ($now->timestamp - CarbonImmutable::parse($oldest, 'UTC')->timestamp > 180 ? 'delayed' : 'queued'), 'queued_slots' => $queued->count(), 'oldest_lag_seconds' => $oldest ? max(0, $now->timestamp - CarbonImmutable::parse($oldest, 'UTC')->timestamp) : null,
            'missed_slots' => JobRun::forOrganization($org)->where('resource_type', 'monitor')->sum('missed_slots'), 'coalesced_runs' => JobRun::forOrganization($org)->where('state', 'coalesced')->count(), 'failed_runs' => JobRun::forOrganization($org)->where('resource_type', 'monitor')->where('state', 'failed')->count()];
        foreach (['storage', 'notification', 'secrets_manager', 'independent_watchdog'] as $component) {
            $components[$component] = ['state' => 'not_configured', 'last_observed_at' => null];
        }
        $components['notification'] = app(NotificationHealth::class)->snapshot($org);

        return ['components' => $components, 'public_probes' => config('opshub.public_probes_enabled') ? 'enabled_live_unverified' : 'disabled', 'boundary' => 'Scheduler/worker heartbeat is local evidence. Independent watchdog, storage, notification delivery and secrets manager require separate deployment validation.'];
    }
}
