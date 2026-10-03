<?php

namespace Tests\Feature;

use App\Domain\IdentityAccess\Role;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FoundationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_read_own_organization_only(): void
    {
        $organization = Organization::create(['name' => 'Solveit', 'timezone' => 'Asia/Jakarta']);
        $otherOrganization = Organization::create(['name' => 'Other', 'timezone' => 'Asia/Jakarta']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        Membership::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => Role::Viewer]);

        $this->actingAs($user)
            ->getJson("/api/v1/foundation/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $organization->id);

        $this->actingAs($user)
            ->getJson("/api/v1/foundation/organizations/{$otherOrganization->id}")
            ->assertForbidden();
    }

    public function test_inactive_user_cannot_create_a_session(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => Hash::make('password'),
            'is_active' => false,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_public_registration_route_is_not_available(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_owner_mfa_boundary_can_be_enforced(): void
    {
        config()->set('opshub.require_owner_mfa', true);
        $organization = Organization::create(['name' => 'Solveit', 'timezone' => 'Asia/Jakarta']);
        $user = User::factory()->create(['email_verified_at' => now(), 'mfa_enabled_at' => null]);
        Membership::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => Role::Owner]);

        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }
}
