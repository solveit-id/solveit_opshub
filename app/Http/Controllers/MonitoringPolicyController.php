<?php

namespace App\Http\Controllers;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\IdentityAccess\ProjectAccess;
use App\Application\PolicyScheduling\EffectiveMonitoringPolicyService;
use App\Application\PolicyScheduling\MonitoringPolicyConfiguration;
use App\Domain\IdentityAccess\Role;
use App\Http\Requests\Policy\MonitoringPolicyRequest;
use App\Http\Requests\Policy\PolicyAssignmentRequest;
use App\Models\Organization;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\PolicyVersion;
use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MonitoringPolicyController extends Controller
{
    public function __construct(
        private readonly OrganizationAuthorizationService $authorization,
        private readonly MonitoringPolicyConfiguration $configuration,
        private readonly EffectiveMonitoringPolicyService $effectivePolicy,
        private readonly AuditWriter $audit,
    ) {}

    public function store(MonitoringPolicyRequest $request, Organization $organization): JsonResponse
    {
        $this->owner($request, $organization);
        $data = $request->validated();
        $this->configuration->validate($data['configuration']);
        $policy = Policy::create(['organization_id' => $organization->id, 'name' => $data['name'], 'kind' => 'monitoring', 'status' => 'draft', 'draft_configuration' => $data['configuration']]);
        $this->audit($request, $organization, 'policy.monitoring.drafted', $policy, [], $policy->getAttributes());

        return response()->json(['data' => $policy], 201);
    }

    public function update(MonitoringPolicyRequest $request, Organization $organization, Policy $policy): JsonResponse
    {
        $this->owner($request, $organization);
        $this->within($policy, $organization);
        $data = $request->validated();
        $this->configuration->validate($data['configuration']);
        $before = $policy->getAttributes();
        $policy->update(['name' => $data['name'], 'draft_configuration' => $data['configuration'], 'status' => 'draft', 'version' => $policy->version + 1]);
        $this->audit($request, $organization, 'policy.monitoring.draft_updated', $policy, $before, $policy->getAttributes());

        return response()->json(['data' => $policy->fresh()]);
    }

    public function publish(Request $request, Organization $organization, Policy $policy): JsonResponse
    {
        $this->owner($request, $organization);
        $this->within($policy, $organization);
        $config = $policy->draft_configuration;
        $this->configuration->validate($config ?? []);
        $version = DB::transaction(function () use ($request, $organization, $policy, $config): PolicyVersion {
            $next = (int) PolicyVersion::query()->where('policy_id', $policy->id)->max('version') + 1;
            $version = PolicyVersion::create(['organization_id' => $organization->id, 'policy_id' => $policy->id, 'version' => $next, 'configuration' => $config, 'published_at' => now(), 'published_by_user_id' => $request->user()->id]);
            $policy->update(['status' => 'published', 'version' => $next]);
            $this->audit($request, $organization, 'policy.monitoring.published', $version, [], $version->getAttributes());

            return $version;
        });

        return response()->json(['data' => $version], 201);
    }

    public function assign(PolicyAssignmentRequest $request, Organization $organization, PolicyVersion $policyVersion): JsonResponse
    {
        $this->owner($request, $organization);
        $this->within($policyVersion, $organization);
        $data = $request->validated();
        $project = Project::query()->forOrganization($organization)->findOrFail($data['project_id']);
        $this->effectivePolicy->validateOverrides($policyVersion->configuration, $data['overrides']);
        $assignment = DB::transaction(function () use ($request, $organization, $policyVersion, $project, $data): PolicyAssignment {
            PolicyAssignment::query()->forOrganization($organization)->where('resource_type', 'project')->where('resource_id', $project->id)->where('is_active', true)->update(['is_active' => false]);
            $assignment = PolicyAssignment::create(['organization_id' => $organization->id, 'policy_version_id' => $policyVersion->id, 'resource_type' => 'project', 'resource_id' => $project->id, 'overrides' => $data['overrides'], 'is_active' => true]);
            $this->audit($request, $organization, 'policy.monitoring.assigned', $assignment, [], $assignment->getAttributes());

            return $assignment;
        });

        return response()->json(['data' => $assignment], 201);
    }

    public function preview(Request $request, Organization $organization, Project $project): JsonResponse
    {
        $this->within($project, $organization);
        app(ProjectAccess::class)->requireProject($request->user(), $organization, $project);

        return response()->json(['data' => $this->effectivePolicy->preview($organization, $project)]);
    }

    private function owner(Request $request, Organization $organization): void
    {
        $membership = $this->authorization->membership($request->user(), $organization);
        if ($membership?->role !== Role::Owner) {
            throw new AuthorizationException('Hanya Owner organisasi yang dapat mengubah policy.');
        }
    }

    private function within(object $model, Organization $organization): void
    {
        abort_unless($model->organization_id === $organization->id, 404);
    }

    private function audit(Request $request, Organization $organization, string $action, object $model, array $before, array $after): void
    {
        $this->audit->write($organization, $action, class_basename($model), $model->id, 'success', $request->user(), $before, $after, ['ability' => 'policy.manage'], requestId: $request->header('X-Request-ID'));
    }
}
