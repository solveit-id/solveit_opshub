<?php

namespace Tests\Feature;

use App\Domain\IdentityAccess\Role;
use App\Models\Incident;
use App\Models\Membership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MonitoringFixture;
use Tests\TestCase;

class MonitoringWorkflowTest extends TestCase
{
    use MonitoringFixture, RefreshDatabase;

    public function test_project_assignment_filters_registry_overview_and_denies_unassigned_detail(): void
    {
        [$org, $project, $monitor] = $this->graph();
        $viewer = User::factory()->create();
        Membership::create(['organization_id' => $org->id, 'user_id' => $viewer->id, 'role' => Role::Viewer, 'is_active' => true]);
        $this->actingAs($viewer)->getJson("/api/v1/organizations/{$org->id}/monitoring/overview")->assertOk()->assertJsonCount(0, 'data.projects');
        $this->get("/organizations/{$org->id}/registry/projects/{$project->id}")->assertNotFound();
        $this->getJson("/api/v1/organizations/{$org->id}/registry")->assertOk()->assertJsonCount(0, 'data.projects')->assertJsonCount(0, 'data.assets');
        DB::table('project_accesses')->insert(['organization_id' => $org->id, 'project_id' => $project->id, 'user_id' => $viewer->id]);
        $this->getJson("/api/v1/organizations/{$org->id}/monitoring/overview")->assertOk()->assertJsonCount(1, 'data.projects')->assertJsonPath('data.projects.0.health', 'unknown');
        $this->get("/organizations/{$org->id}/overview")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Monitoring/Overview')->has('projects', 1));
        $this->get("/organizations/{$org->id}/assets/{$monitor->asset_id}")->assertOk();
    }

    public function test_incident_actions_require_role_scope_versions_and_idempotency(): void
    {
        [$org, $project, $monitor] = $this->graph();
        $at = CarbonImmutable::now('UTC');
        foreach ([0, 1, 2] as $minute) {
            $this->sample($monitor, $at->addMinutes($minute), 'fail');
        }
        $incident = Incident::sole();
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        foreach ([[$owner, Role::Owner], [$viewer, Role::Viewer]] as [$user, $role]) {
            Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true]);
        }
        $base = "/api/v1/organizations/{$org->id}/monitoring/incidents/{$incident->id}";
        $this->actingAs($viewer)->getJson($base)->assertNotFound();
        DB::table('project_accesses')->insert(['organization_id' => $org->id, 'project_id' => $project->id, 'user_id' => $viewer->id]);
        $this->getJson($base)->assertOk()->assertJsonPath('data.canManage', false);
        $this->postJson($base.'/acknowledge', ['version' => $incident->version], ['Idempotency-Key' => 'request-key-1'])->assertForbidden();
        $this->actingAs($owner);
        $payload = ['version' => $incident->version];
        $this->postJson($base.'/acknowledge', $payload, ['Idempotency-Key' => 'request-key-1'])->assertOk()->assertJsonPath('data.state', 'acknowledged');
        $this->postJson($base.'/acknowledge', $payload, ['Idempotency-Key' => 'request-key-1'])->assertOk();
        $this->assertDatabaseCount('idempotency_keys', 1);
        $this->postJson($base.'/acknowledge', $payload, ['Idempotency-Key' => 'request-key-2'])->assertConflict();
        $this->postJson($base.'/close', ['version' => $incident->fresh()->version, 'summary' => 'Bearer leaked-token'], ['Idempotency-Key' => 'request-key-3'])->assertUnprocessable();
        $this->get("/organizations/{$org->id}/incidents/{$incident->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Monitoring/Incident')->has('incident.observations', 3));
        $this->assertDatabaseHas('audit_events', ['action' => 'incident.acknowledge']);
    }

    public function test_registry_mutations_check_assigned_project_and_api_has_session_csrf_middleware(): void
    {
        [$org, $project] = $this->graph();
        $operator = User::factory()->create();
        Membership::create(['organization_id' => $org->id, 'user_id' => $operator->id, 'role' => Role::Operator, 'is_active' => true]);
        $endpoint = "/api/v1/organizations/{$org->id}/registry/projects/{$project->id}";
        $this->actingAs($operator)->deleteJson($endpoint)->assertNotFound();
        DB::table('project_accesses')->insert(['organization_id' => $org->id, 'project_id' => $project->id, 'user_id' => $operator->id]);
        $this->deleteJson($endpoint)->assertOk();
        $route = app('router')->getRoutes()->match(Request::create($endpoint, 'DELETE'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains(PreventRequestForgery::class, app('router')->resolveMiddleware(['web']));
    }
}
