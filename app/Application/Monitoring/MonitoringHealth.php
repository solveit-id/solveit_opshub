<?php

namespace App\Application\Monitoring;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Project;
use Carbon\CarbonImmutable;

class MonitoringHealth
{
    public function monitor(Monitor $monitor, ?CarbonImmutable $now = null): array
    {
        $latest = $monitor->observations()->orderByDesc('scheduled_at')->first();
        $freshness = $latest === null ? 'never_observed' : ($latest->fresh_until->lessThanOrEqualTo($now ?? CarbonImmutable::now('UTC')) ? 'stale' : 'fresh');

        return [
            'id' => $monitor->id, 'kind' => $monitor->kind, 'state' => ! $monitor->enabled ? 'paused' : ($freshness === 'fresh' ? $monitor->state : 'unknown'),
            'freshness' => $freshness, 'last_known_state' => $monitor->state, 'last_observation' => $latest,
        ];
    }

    public function project(Project $project, ?CarbonImmutable $now = null): array
    {
        $monitors = Monitor::forOrganization($project->organization_id)->whereHas('projects', fn ($query) => $query->where('projects.id', $project->id))->get();
        $checks = $monitors->map(fn ($monitor) => $this->monitor($monitor, $now));
        $production = $monitors->where('environment_kind', 'production');
        $coreFresh = $production->contains(fn ($monitor) => $monitor->kind === 'http' && $this->monitor($monitor, $now)['freshness'] === 'fresh' && in_array($this->monitor($monitor, $now)['last_observation']?->outcome, ['pass', 'fail', 'warn'], true));
        $critical = Incident::forOrganization($project->organization_id)->whereIn('monitor_id', $production->pluck('id'))->where('severity', 'critical')->whereNotIn('state', ['resolved', 'closed'])->exists();
        $gaps = ['backup' => 'not_configured', 'application_health' => 'unsupported'];
        $unhealthy = $checks->contains(fn ($check) => $check['freshness'] !== 'fresh' || ! in_array($check['last_observation']?->outcome, ['pass', 'not_applicable'], true));
        $health = $critical ? 'critical' : (! $coreFresh ? 'unknown' : 'warning');

        // Internal capability coverage remains partial until the M3 verified backup paths exist.
        return ['health' => $health, 'coverage' => $monitors->isEmpty() ? 'unconfigured' : 'partial', 'checks' => $checks->values()->all(), 'capability_gaps' => $gaps, 'public_checks_passing' => $coreFresh && ! $unhealthy];
    }
}
