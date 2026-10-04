<?php

namespace App\Application\Monitoring;

use App\Application\TelegramNotifications\OutboxWriter;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\Observation;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class IncidentEngine
{
    public function inMaintenance(Monitor $monitor, CarbonImmutable $at): bool
    {
        return MaintenanceWindow::forOrganization($monitor->organization_id)->where('monitor_id', $monitor->id)->where('starts_at', '<=', $at->utc())->where('ends_at', '>', $at->utc())->exists();
    }

    public function evaluate(Observation $observation): ?Incident
    {
        return DB::transaction(function () use ($observation): ?Incident {
            $monitor = Monitor::whereKey($observation->monitor_id)->lockForUpdate()->firstOrFail();
            $incident = Incident::where('active_monitor_id', $monitor->id)->first();
            if ($monitor->last_evaluated_slot !== null && $monitor->last_evaluated_slot->greaterThanOrEqualTo($observation->scheduled_at)) {
                return $incident;
            }
            $monitor->last_evaluated_slot = $observation->scheduled_at;
            $eligible = in_array($observation->outcome, ['pass', 'fail', 'warn'], true);
            $failed = in_array($observation->outcome, ['fail', 'warn'], true);
            $suppressed = $this->inMaintenance($monitor, $observation->completed_at);
            if (! $eligible) {
                $monitor->fill(['failures' => 0, 'successes' => 0, 'first_failed_at' => null, 'first_recovery_at' => null]);
                if ($incident === null || in_array($incident->state, ['resolved', 'closed'], true)) {
                    $monitor->state = 'unknown';
                }
            } elseif ($failed) {
                $monitor->successes = 0;
                $monitor->first_recovery_at = null;
                $monitor->failures++;
                $monitor->first_failed_at ??= $observation->completed_at;
                $threshold = $monitor->kind === 'tls' ? 1 : ($monitor->configuration['failure_threshold'] ?? 3);
                $monitor->state = $monitor->failures >= $threshold || ($incident !== null && $incident->state !== 'resolved') ? 'down' : 'suspect';
                if ($monitor->failures >= $threshold) {
                    $confirmed = false;
                    if ($incident === null) {
                        $previous = Incident::where('monitor_id', $monitor->id)->latest('id')->first();
                        $incident = Incident::create([
                            'organization_id' => $monitor->organization_id, 'monitor_id' => $monitor->id, 'active_monitor_id' => $monitor->id,
                            'previous_incident_id' => $previous?->id, 'state' => 'open', 'severity' => $observation->outcome === 'warn' ? 'warning' : 'critical',
                            'reason_code' => $observation->reason_code, 'first_failed_at' => $monitor->first_failed_at,
                            'confirmed_down_at' => $observation->completed_at, 'last_failed_at' => $observation->completed_at, 'alert_pending' => true,
                        ]);
                        $confirmed = true;
                    } elseif ($incident->state === 'resolved') {
                        $incident->fill(['state' => 'open', 'confirmed_down_at' => $observation->completed_at, 'first_failed_at' => $monitor->first_failed_at,
                            'confirmed_recovered_at' => null, 'first_recovery_sample_at' => null, 'acknowledged_at' => null, 'alert_pending' => true]);
                        $confirmed = true;
                    }
                    $incident->fill(['last_failed_at' => $observation->completed_at, 'reason_code' => $observation->reason_code, 'severity' => $observation->outcome === 'warn' ? 'warning' : 'critical', 'version' => $incident->version + 1]);
                    $incident->save();
                    if ($confirmed) {
                        foreach ($monitor->activeProjects()->get() as $project) {
                            $incident->projects()->syncWithoutDetaching([$project->id => ['environment_id' => $project->pivot->environment_id]]);
                        }
                        $preceding = Observation::where('monitor_id', $monitor->id)->where('completed_at', '>=', $monitor->first_failed_at)->where('completed_at', '<=', $observation->completed_at)->pluck('id');
                        $incident->observations()->syncWithoutDetaching($preceding->all());
                        DB::table('observations')->whereIn('id', $preceding)->update(['retention_hold' => true]);
                        $this->transition($incident, 'confirmed_down', $observation, $suppressed);
                        $episodes = DB::table('incident_transitions')->join('incidents', 'incidents.id', '=', 'incident_transitions.incident_id')->where('incidents.monitor_id', $monitor->id)
                            ->where('event', 'confirmed_down')->where('occurred_at', '>=', $observation->completed_at->subMinutes(30)->format('Y-m-d H:i:s'))->count();
                        if ($episodes >= 3) {
                            $incident->update(['flapping' => true]);
                        }
                    }
                }
            } else {
                $monitor->failures = 0;
                $monitor->first_failed_at = null;
                $monitor->successes++;
                $monitor->first_recovery_at ??= $observation->completed_at;
                $hasProblem = $incident !== null && ! in_array($incident->state, ['resolved', 'closed'], true);
                $monitor->state = $hasProblem && $monitor->successes < ($monitor->configuration['recovery_threshold'] ?? 2) ? 'recovering' : 'up';
                if ($hasProblem) {
                    $incident->fill(['first_recovery_sample_at' => $monitor->first_recovery_at, 'version' => $incident->version + 1]);
                    if ($monitor->state === 'up') {
                        $incident->fill(['state' => 'resolved', 'confirmed_recovered_at' => $observation->completed_at]);
                        $this->transition($incident, 'confirmed_recovery', $observation, $suppressed);
                        if (! $suppressed) {
                            $this->event($incident, 'incident.resolved', ['delivery_guard' => 'require_delivered_down_for_destination']);
                        }
                    }
                    $incident->save();
                }
            }
            if ($incident !== null) {
                $incident->observations()->syncWithoutDetaching([$observation->id]);
                DB::table('observations')->where('id', $observation->id)->update(['retention_hold' => true]);
                if ($incident->alert_pending && ! $suppressed && ! in_array($incident->state, ['resolved', 'closed'], true)) {
                    $alreadyCoalesced = $incident->flapping && DB::table('incident_transitions')->join('incidents', 'incidents.id', '=', 'incident_transitions.incident_id')
                        ->where('incidents.monitor_id', $monitor->id)->where('event', 'stability_warning')->where('occurred_at', '>=', $observation->completed_at->subMinutes(30)->format('Y-m-d H:i:s'))->exists();
                    if (! $alreadyCoalesced) {
                        $this->event($incident, $incident->flapping ? 'incident.stability_warning' : 'incident.opened', ['reason_code' => $incident->reason_code]);
                        if ($incident->flapping) {
                            $this->transition($incident, 'stability_warning', $observation, false);
                        }
                    }
                    $incident->update(['alert_pending' => false]);
                }
            }
            $monitor->save();

            return $incident;
        });
    }

    private function transition(Incident $incident, string $event, Observation $observation, bool $suppressed): void
    {
        DB::table('incident_transitions')->insert(['incident_id' => $incident->id, 'event' => $event, 'occurred_at' => $observation->completed_at->format('Y-m-d H:i:s'), 'suppressed' => $suppressed, 'reason_code' => $observation->reason_code]);
    }

    private function event(Incident $incident, string $type, array $extra): void
    {
        app(OutboxWriter::class)->record(Organization::findOrFail($incident->organization_id), $type, 'incident', $incident->id, $incident->version, [
            'incident_id' => $incident->id, 'monitor_id' => $incident->monitor_id, 'environment_kind' => $incident->monitor->environment_kind,
            'impacted_project_ids' => $incident->projects()->pluck('projects.id')->all(), ...$extra,
        ]);
    }
}
