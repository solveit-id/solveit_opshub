<?php

namespace App\Http\Controllers;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\IdentityAccess\ProjectAccess;
use App\Application\Monitoring\IncidentActions;
use App\Application\Monitoring\MonitoringHealth;
use App\Application\Monitoring\RuntimeHealth;
use App\Application\PolicyScheduling\IdempotencyService;
use App\Models\Asset;
use App\Models\IdempotencyKey;
use App\Models\Incident;
use App\Models\Membership;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\Project;
use App\Rules\RejectSecretBearingValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MonitoringController extends Controller
{
    public function __construct(private readonly ProjectAccess $access, private readonly MonitoringHealth $health, private readonly OrganizationAuthorizationService $authorization) {}

    public function overview(Request $request, Organization $organization)
    {
        $projects = $this->access->query($request->user(), $organization)->get()->map(fn ($project) => [...$project->only(['id', 'name', 'code', 'lifecycle', 'internal_pic_user_id']), ...$this->health->project($project)]);
        $projects = $projects->sortBy(fn ($project) => [array_search($project['health'], ['critical', 'warning', 'unknown', 'healthy']), $project['name']])->values();
        $ids = $projects->pluck('id');
        $incidents = Incident::forOrganization($organization)->whereHas('projects', fn ($query) => $query->whereIn('projects.id', $ids))->orderByRaw("CASE severity WHEN 'critical' THEN 0 ELSE 1 END")->orderBy('confirmed_down_at')->get()->map(fn ($incident) => $this->incidentData($request, $organization, $incident, false));
        $data = ['organization' => $organization->only(['id', 'name', 'timezone']), 'projects' => $projects, 'incidents' => $incidents, 'counts' => $projects->countBy('health'), 'validation' => 'Live monitoring belum diverifikasi; evidence fake selalu diberi label.'];

        return $request->is('api/*') ? response()->json(['data' => $data]) : Inertia::render('Monitoring/Overview', $data);
    }

    public function runtimeHealth(Request $request, Organization $organization)
    {
        $data = ['organization' => $organization->only(['id', 'name', 'timezone']), ...app(RuntimeHealth::class)->snapshot($organization)];

        return $request->is('api/*') ? response()->json(['data' => $data]) : Inertia::render('Monitoring/Health', $data);
    }

    public function incident(Request $request, Organization $organization, Incident $incident)
    {
        $this->access->requireIncident($request->user(), $organization, $incident);
        $data = ['organization' => $organization->only(['id', 'name', 'timezone']), 'incident' => $this->incidentData($request, $organization, $incident, true), 'canManage' => $this->canManage($request, $organization, $incident),
            'assignees' => Membership::where('organization_id', $organization->id)->where('is_active', true)->whereHas('user', fn ($query) => $query->where('is_active', true))->with('user:id,name')->get()->filter(fn ($membership) => $membership->role->can('incident.manage') && $incident->projects()->get()->every(fn ($project) => $this->access->query($membership->user, $organization)->whereKey($project->id)->exists()))->map(fn ($membership) => $membership->user->only(['id', 'name']))->values()];

        return $request->is('api/*') ? response()->json(['data' => $data]) : Inertia::render('Monitoring/Incident', $data);
    }

    public function asset(Request $request, Organization $organization, Asset $asset)
    {
        abort_unless($asset->organization_id === $organization->id, 404);
        $visible = $this->access->query($request->user(), $organization)->pluck('id');
        $usages = $asset->usages()->whereIn('project_id', $visible)->with(['project:id,name', 'environment:id,display_name'])->get();
        abort_unless($this->access->owner($request->user(), $organization) || $usages->isNotEmpty(), 404);

        return Inertia::render('Monitoring/Asset', ['organization' => $organization->only(['id', 'name', 'timezone']), 'asset' => $asset->only(['id', 'kind', 'canonical_identity', 'responsibility', 'source', 'verified_at', 'notes']), 'usages' => $usages]);
    }

    public function action(Request $request, Organization $organization, Incident $incident, string $action)
    {
        $this->authorization->require($request->user(), $organization, 'incident.manage');
        $this->access->requireIncident($request->user(), $organization, $incident, true);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'summary' => ['nullable', 'string', 'max:2000', new RejectSecretBearingValue], 'assignee_user_id' => ['nullable', 'integer']]);
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[a-zA-Z0-9-]{8,80}$/', $key), 422, 'Idempotency-Key wajib.');
        if ($action === 'assign') {
            $assignee = Membership::where('organization_id', $organization->id)->where('user_id', $data['assignee_user_id'] ?? 0)->where('is_active', true)->first();
            abort_unless($assignee !== null && $assignee->user->is_active && $assignee->role->can('incident.manage'), 422, 'Assignee harus operator aktif dalam scope.');
            $this->access->requireIncident($assignee->user, $organization, $incident, true);
        }
        $response = DB::transaction(function () use ($request, $organization, $incident, $action, $data, $key): array {
            Monitor::whereKey($incident->monitor_id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$incident->id, $action, $data]));
            $existing = IdempotencyKey::where('organization_id', $organization->id)->where('actor_user_id', $request->user()->id)->where('action', 'incident.action')->where('key', $key)->first();
            if ($existing !== null) {
                abort_unless($existing->request_hash === $hash, 409, 'Key digunakan untuk request berbeda.');

                return $existing->response;
            }
            $changed = app(IncidentActions::class)->change($incident, $request->user(), $action, $data['version'], $data);
            $result = $changed->only(['id', 'state', 'version', 'assignee_user_id']);
            app(IdempotencyService::class)->remember($organization, $request->user(), 'incident.action', $key, $hash, $result, now()->addDay());

            return $result;
        });

        return response()->json(['data' => $response]);
    }

    public function grantAccess(Request $request, Organization $organization, Project $project)
    {
        abort_unless($this->access->owner($request->user(), $organization), 403);
        $this->access->requireProject($request->user(), $organization, $project);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'enabled' => ['required', 'boolean']]);
        abort_unless(Membership::where('organization_id', $organization->id)->where('user_id', $data['user_id'])->where('is_active', true)->exists(), 422);
        DB::transaction(function () use ($request, $organization, $project, $data): void {
            $scope = ['organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $data['user_id']];
            if ($data['enabled']) {
                DB::table('project_accesses')->updateOrInsert($scope);
            } else {
                DB::table('project_accesses')->where($scope)->delete();
            }
            app(AuditWriter::class)->write($organization, 'project.access.changed', 'project', $project->id, 'success', $request->user(), after: $data);
        });

        return response()->json(['data' => $data]);
    }

    private function canManage(Request $request, Organization $organization, Incident $incident): bool
    {
        $ids = $incident->projects()->pluck('projects.id');

        return $this->authorization->can($request->user(), $organization, 'incident.manage') && ($this->access->owner($request->user(), $organization) || ($ids->isNotEmpty() && $this->access->query($request->user(), $organization)->whereIn('id', $ids)->count() === $ids->count()));
    }

    private function incidentData(Request $request, Organization $organization, Incident $incident, bool $detail): array
    {
        $visible = $this->access->query($request->user(), $organization)->pluck('id');
        $data = [...$incident->only(['id', 'state', 'severity', 'reason_code', 'assignee_user_id', 'acknowledged_at', 'first_failed_at', 'confirmed_down_at', 'last_failed_at', 'first_recovery_sample_at', 'confirmed_recovered_at', 'closure_summary', 'version', 'flapping']),
            'impacted_projects' => $incident->projects()->whereIn('projects.id', $visible)->get(['projects.id', 'projects.name']), 'environment_kind' => $incident->monitor->environment_kind];
        if ($detail) {
            $data['observations'] = $incident->observations()->orderByDesc('scheduled_at')->limit(100)->get();
            $data['timeline'] = DB::table('incident_transitions')->where('incident_id', $incident->id)->orderBy('occurred_at')->get();
            $data['tasks'] = [];
            $data['client_followups'] = [];
            $data['workflow_boundary'] = 'Task maintenance dan follow-up client tersedia pada milestone M2/M4.';
        }

        return $data;
    }
}
