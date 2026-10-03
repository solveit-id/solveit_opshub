<?php

namespace Tests\Feature;

use App\Domain\IdentityAccess\Role;
use App\Models\Client;
use App\Models\JobRun;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_build_a_scoped_client_project_environment_and_shared_asset_graph(): void
    {
        [$organization, $owner] = $this->organizationWith(Role::Owner);
        $this->actingAs($owner);

        $client = $this->postJson($this->endpoint($organization, 'clients'), [
            'name' => 'PT Contoh Nusantara',
            'status' => 'active',
            'notes' => 'Kontrak maintenance aktif.',
        ])->assertCreated()->json('data');

        $this->postJson($this->endpoint($organization, "clients/{$client['id']}/contacts"), [
            'name' => 'Nadia Client',
            'contact_value' => 'nadia@example.test',
            'purpose' => 'PIC renewal',
            'preferred_manual_channel' => 'email',
        ])->assertCreated()->assertJsonPath('data.client_id', $client['id']);

        $projectOne = $this->createProject($organization, $client['id'], 'CONTOH-WEB', 'Website Contoh');
        $projectTwo = $this->createProject($organization, $client['id'], 'CONTOH-API', 'API Contoh');

        $production = $this->postJson($this->endpoint($organization, "projects/{$projectOne['id']}/environments"), [
            'kind' => 'production',
            'display_name' => 'Production',
        ])->assertCreated()->json('data');

        $this->postJson($this->endpoint($organization, "projects/{$projectOne['id']}/environments"), [
            'kind' => 'production',
            'display_name' => 'Production kedua',
        ])->assertUnprocessable()->assertJsonValidationErrors('kind');

        $asset = $this->postJson($this->endpoint($organization, 'assets'), [
            'kind' => 'hosting_account',
            'canonical_identity' => 'account-contoh-01',
            'responsibility' => 'shared',
            'source' => 'manual_inventory',
            'notes' => 'Akun bersama untuk dua proyek.',
        ])->assertCreated()->json('data');

        $this->postJson($this->endpoint($organization, "assets/{$asset['id']}/usages"), [
            'project_id' => $projectOne['id'],
            'environment_id' => $production['id'],
            'purpose' => 'hosting',
        ])->assertCreated();
        $this->postJson($this->endpoint($organization, "assets/{$asset['id']}/usages"), [
            'project_id' => $projectTwo['id'],
            'purpose' => 'hosting',
        ])->assertCreated();

        $this->getJson($this->endpoint($organization))
            ->assertOk()
            ->assertJsonPath('data.projects.0.client.name', 'PT Contoh Nusantara')
            ->assertJsonPath('data.assets.0.usages_count', 2);

        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $organization->id,
            'action' => 'registry.asset_usage.created',
        ]);
    }

    public function test_registry_is_scoped_and_viewers_cannot_mutate_it(): void
    {
        [$organization, $viewer] = $this->organizationWith(Role::Viewer);
        [$otherOrganization] = $this->organizationWith(Role::Owner);
        Client::create(['organization_id' => $otherOrganization->id, 'name' => 'Client Lain', 'status' => 'active']);

        $this->actingAs($viewer)
            ->getJson($this->endpoint($organization))
            ->assertOk()
            ->assertJsonCount(0, 'data.clients');

        $this->postJson($this->endpoint($organization, 'clients'), [
            'name' => 'Tidak boleh dibuat',
            'status' => 'active',
        ])->assertForbidden();

        $this->getJson($this->endpoint($otherOrganization))->assertForbidden();
    }

    public function test_registry_rejects_secret_bearing_notes_and_keeps_asset_identity_canonical(): void
    {
        [$organization, $owner] = $this->organizationWith(Role::Owner);
        $this->actingAs($owner);

        $this->postJson($this->endpoint($organization, 'clients'), [
            'name' => 'PT Aman',
            'status' => 'active',
            'notes' => 'Authorization: Bearer not-a-registry-note',
        ])->assertUnprocessable()->assertJsonValidationErrors('notes');

        $payload = [
            'kind' => 'domain',
            'canonical_identity' => 'contoh.test',
            'responsibility' => 'client',
            'source' => 'client_confirmation',
        ];
        $this->postJson($this->endpoint($organization, 'assets'), $payload)->assertCreated();
        $this->postJson($this->endpoint($organization, 'assets'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('canonical_identity');
    }

    public function test_archiving_a_project_preserves_history_and_cancels_only_future_unstarted_work(): void
    {
        [$organization, $owner] = $this->organizationWith(Role::Owner);
        $client = Client::create(['organization_id' => $organization->id, 'name' => 'PT Arsip', 'status' => 'active']);
        $project = Project::create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'code' => 'ARSIP-WEB',
            'name' => 'Website Arsip',
            'lifecycle' => 'active',
            'criticality' => 'normal',
        ]);
        $futureJob = JobRun::create([
            'organization_id' => $organization->id,
            'resource_type' => 'project',
            'resource_id' => $project->id,
            'kind' => 'http_probe',
            'scheduled_slot' => now()->addHour(),
            'state' => 'queued',
        ]);
        $leasedJob = JobRun::create([
            'organization_id' => $organization->id,
            'resource_type' => 'project',
            'resource_id' => $project->id,
            'kind' => 'tls_probe',
            'scheduled_slot' => now()->addHour(),
            'state' => 'leased',
        ]);

        $this->actingAs($owner)
            ->deleteJson($this->endpoint($organization, "projects/{$project->id}"))
            ->assertOk()
            ->assertJsonPath('data.lifecycle', 'archived')
            ->assertJsonPath('meta.cancelled_future_jobs', 1);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'lifecycle' => 'archived']);
        $this->assertDatabaseHas('job_runs', ['id' => $futureJob->id, 'state' => 'cancelled']);
        $this->assertDatabaseHas('job_runs', ['id' => $leasedJob->id, 'state' => 'leased']);
        $this->assertDatabaseHas('audit_events', ['action' => 'registry.project.archived', 'object_id' => (string) $project->id]);
    }

    public function test_registry_pages_render_only_for_a_scoped_member(): void
    {
        [$organization, $owner] = $this->organizationWith(Role::Owner);

        $this->actingAs($owner)
            ->get("/organizations/{$organization->id}/registry")
            ->assertOk()
            ->assertSee('Registry\/Index');
    }

    private function createProject(Organization $organization, int $clientId, string $code, string $name): array
    {
        return $this->postJson($this->endpoint($organization, 'projects'), [
            'client_id' => $clientId,
            'code' => $code,
            'name' => $name,
            'lifecycle' => 'active',
            'criticality' => 'normal',
            'stack_tags' => ['laravel'],
        ])->assertCreated()->json('data');
    }

    /** @return array{Organization, User} */
    private function organizationWith(Role $role): array
    {
        $organization = Organization::create(['name' => fake()->company(), 'timezone' => 'Asia/Jakarta']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        Membership::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role]);

        return [$organization, $user];
    }

    private function endpoint(Organization $organization, string $suffix = ''): string
    {
        $base = "/api/v1/organizations/{$organization->id}/registry";

        return $suffix === '' ? $base : "{$base}/{$suffix}";
    }
}
