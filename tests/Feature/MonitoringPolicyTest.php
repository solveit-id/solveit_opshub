<?php

namespace Tests\Feature;

use App\Application\PolicyScheduling\MonitoringPolicyConfiguration;
use App\Domain\IdentityAccess\Role;
use App\Models\Asset;
use App\Models\AssetUsage;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\PolicyVersion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class MonitoringPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_policy_is_immutable_and_draft_changes_do_not_change_active_project(): void
    {
        [$organization, $owner] = $this->organizationWith(Role::Owner);
        $project = $this->project($organization);
        $configuration = app(MonitoringPolicyConfiguration::class)->defaults('Asia/Jakarta');
        $this->actingAs($owner);

        $policy = $this->postJson($this->endpoint($organization, 'monitoring-policies'), [
            'name' => 'Website standar', 'kind' => 'monitoring', 'configuration' => $configuration,
        ])->assertCreated()->json('data');
        $version = $this->postJson($this->endpoint($organization, "monitoring-policies/{$policy['id']}/publish"))->assertCreated()->json('data');
        $this->postJson($this->endpoint($organization, "monitoring-policy-versions/{$version['id']}/assignments"), [
            'project_id' => $project->id, 'overrides' => [],
        ])->assertCreated();

        $before = $this->getJson($this->endpoint($organization, "projects/{$project->id}/effective-monitoring-policy"))
            ->assertOk()->json('data');
        $this->assertSame($version['id'], $before['policy_version_id']);
        $this->assertSame('partial', $before['coverage']);
        $this->assertStringEndsWith('+07:00', $before['checks']['http']['next_due_at']);

        $changed = $configuration;
        $changed['checks']['dns']['enabled'] = false;
        $this->patchJson($this->endpoint($organization, "monitoring-policies/{$policy['id']}"), [
            'name' => 'Website standar revisi', 'kind' => 'monitoring', 'configuration' => $changed,
        ])->assertOk();

        $after = $this->getJson($this->endpoint($organization, "projects/{$project->id}/effective-monitoring-policy"))
            ->assertOk()->json('data');
        $this->assertSame($version['id'], $after['policy_version_id']);
        $this->assertSame('not_configured', $after['checks']['dns']['state']);
        $this->assertSame(21600, $after['checks']['dns']['interval_seconds']);

        $this->expectException(LogicException::class);
        PolicyVersion::findOrFail($version['id'])->update(['version' => 99]);
    }

    public function test_effective_policy_exposes_constrained_override_and_honest_coverage_gaps(): void
    {
        [$organization, $owner] = $this->organizationWith(Role::Owner);
        $project = $this->project($organization);
        $production = Environment::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'kind' => 'production', 'display_name' => 'Production']);
        $url = $this->asset($organization, 'url', 'https://contoh.test');
        $domain = $this->asset($organization, 'domain', 'contoh.test');
        AssetUsage::create(['organization_id' => $organization->id, 'asset_id' => $url->id, 'project_id' => $project->id, 'environment_id' => $production->id, 'purpose' => 'public_endpoint']);
        AssetUsage::create(['organization_id' => $organization->id, 'asset_id' => $domain->id, 'project_id' => $project->id, 'purpose' => 'dns']);
        $this->actingAs($owner);
        $config = app(MonitoringPolicyConfiguration::class)->defaults();
        $policy = $this->postJson($this->endpoint($organization, 'monitoring-policies'), ['name' => 'Standard', 'kind' => 'monitoring', 'configuration' => $config])->assertCreated()->json('data');
        $version = $this->postJson($this->endpoint($organization, "monitoring-policies/{$policy['id']}/publish"))->assertCreated()->json('data');

        $this->postJson($this->endpoint($organization, "monitoring-policy-versions/{$version['id']}/assignments"), [
            'project_id' => $project->id,
            'overrides' => ['checks' => ['http' => ['interval_seconds' => 120]]],
        ])->assertCreated();
        $this->getJson($this->endpoint($organization, "projects/{$project->id}/effective-monitoring-policy"))
            ->assertOk()->assertJsonPath('data.coverage', 'complete')->assertJsonPath('data.checks.http.interval_seconds', 120);

        $this->postJson($this->endpoint($organization, "monitoring-policy-versions/{$version['id']}/assignments"), [
            'project_id' => $project->id,
            'overrides' => ['checks' => ['http' => ['interval_seconds' => 30]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('overrides.checks.http.interval_seconds');
    }

    public function test_only_owner_can_change_global_monitoring_policy(): void
    {
        [$organization, $operator] = $this->organizationWith(Role::Operator);
        $this->actingAs($operator)->postJson($this->endpoint($organization, 'monitoring-policies'), [
            'name' => 'Tidak boleh', 'kind' => 'monitoring', 'configuration' => app(MonitoringPolicyConfiguration::class)->defaults(),
        ])->assertForbidden();
    }

    /** @return array{Organization, User} */
    private function organizationWith(Role $role): array
    {
        $organization = Organization::create(['name' => fake()->company(), 'timezone' => 'Asia/Jakarta']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        Membership::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role]);

        return [$organization, $user];
    }

    private function project(Organization $organization): Project
    {
        $client = Client::create(['organization_id' => $organization->id, 'name' => fake()->company(), 'status' => 'active']);

        return Project::create(['organization_id' => $organization->id, 'client_id' => $client->id, 'code' => 'POLICY-'.fake()->unique()->numerify('###'), 'name' => 'Website Policy', 'lifecycle' => 'active', 'criticality' => 'normal']);
    }

    private function asset(Organization $organization, string $kind, string $identity): Asset
    {
        return Asset::create(['organization_id' => $organization->id, 'kind' => $kind, 'canonical_identity' => $identity, 'responsibility' => 'solveit', 'source' => 'fixture']);
    }

    private function endpoint(Organization $organization, string $suffix): string
    {
        return "/api/v1/organizations/{$organization->id}/registry/{$suffix}";
    }
}
