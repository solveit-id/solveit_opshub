<?php

namespace App\Application\Monitoring;

use App\Application\ActivityEvidence\AuditWriter;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IncidentActions
{
    /** Permission/scope is checked by the authenticated workflow before entering this mutation. */
    public function change(Incident $incident, User $actor, string $action, int $version, array $data = []): Incident
    {
        return DB::transaction(function () use ($incident, $actor, $action, $version, $data): Incident {
            $monitor = Monitor::whereKey($incident->monitor_id)->lockForUpdate()->firstOrFail();
            $locked = Incident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $version, 409, 'Incident berubah; muat ulang sebelum bertindak.');
            $before = $locked->only(['state', 'assignee_user_id', 'version']);
            if ($action === 'close') {
                if ($locked->state !== 'resolved' || $monitor->state !== 'up' || trim($data['summary'] ?? '') === '') {
                    throw ValidationException::withMessages(['summary' => 'Closure membutuhkan recovery terkonfirmasi dan ringkasan tindakan/cause.']);
                }
                $locked->fill(['state' => 'closed', 'active_monitor_id' => null, 'closed_at' => now('UTC'), 'closure_summary' => $data['summary']]);
            } elseif ($action === 'acknowledge' || $action === 'investigate') {
                if (in_array($locked->state, ['resolved', 'closed'], true)) {
                    throw ValidationException::withMessages(['action' => 'Incident sudah pulih; acknowledge tidak diperlukan.']);
                }
                $locked->fill(['state' => $action === 'acknowledge' ? 'acknowledged' : 'investigating', 'acknowledged_at' => $locked->acknowledged_at ?? now('UTC')]);
            } elseif ($action === 'assign') {
                $locked->assignee_user_id = $data['assignee_user_id'];
            } else {
                throw ValidationException::withMessages(['action' => 'Tindakan tidak didukung.']);
            }
            $locked->version++;
            $locked->save();
            DB::table('incident_transitions')->insert(['incident_id' => $locked->id, 'event' => $action, 'occurred_at' => now('UTC')->format('Y-m-d H:i:s'), 'suppressed' => false, 'reason_code' => 'OPERATOR_ACTION']);
            app(AuditWriter::class)->write(Organization::findOrFail($locked->organization_id), 'incident.'.$action, 'incident', $locked->id, 'success', $actor, before: $before, after: $locked->only(['state', 'assignee_user_id', 'closure_summary', 'version']));

            return $locked;
        });
    }
}
