<?php

namespace Tests\Feature;

use App\Application\RenewalFollowups\RenewalScheduler;
use App\Domain\IdentityAccess\Role;
use App\Models\AssetUsage;
use App\Models\ClientFollowup;
use App\Models\Evidence;
use App\Models\Membership;
use App\Models\Project;
use App\Models\TemplateDraft;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RenewalFixture;
use Tests\TestCase;

class RenewalDashboardTest extends TestCase
{
    use RefreshDatabase, RenewalFixture;

    public function test_scoped_pages_show_current_draft_and_read_only_viewer_cannot_generate(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04T03:00:00Z'));
        [$org, $owner, $service, $project] = $this->renewalGraph();
        app(RenewalScheduler::class)->tick($org);
        $f = ClientFollowup::firstOrFail();
        $this->actingAs($owner)->get('/organizations/'.$org->id.'/renewals')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Renewals/Index')->has('items', 1));
        $this->get('/organizations/'.$org->id.'/follow-ups/'.$f->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Renewals/Followup')->where('draft.draft_status', 'ready')->where('canManage', true)->has('contacts', 1));
        $this->get('/organizations/'.$org->id.'/client-templates')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Renewals/Templates')->has('templates', 10));
        $viewer = User::factory()->create();
        Membership::create(['organization_id' => $org->id, 'user_id' => $viewer->id, 'role' => Role::Viewer, 'is_active' => true]);
        $base = '/api/v1/organizations/'.$org->id;
        $this->actingAs($viewer)->getJson($base.'/renewals')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson($base.'/follow-ups/'.$f->id)->assertNotFound();
        DB::table('project_accesses')->insert(['organization_id' => $org->id, 'project_id' => $project->id, 'user_id' => $viewer->id]);
        $this->getJson($base.'/follow-ups/'.$f->id)->assertOk()->assertJsonPath('data.canManage', false)->assertJsonPath('data.fullScope', true);
        $this->postJson($base.'/follow-ups/'.$f->id.'/drafts', ['version' => $f->version])->assertForbidden();
    }

    public function test_partial_shared_scope_does_not_leak_hidden_project_in_client_body_or_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04T03:00:00Z'));
        [$org, $owner, $service, $project] = $this->renewalGraph();
        $hidden = Project::create(['organization_id' => $org->id, 'client_id' => $project->client_id, 'code' => 'HIDDEN', 'name' => 'Hidden client project', 'lifecycle' => 'active', 'internal_pic_user_id' => $owner->id]);
        AssetUsage::create(['organization_id' => $org->id, 'project_id' => $hidden->id, 'asset_id' => $service->asset_id, 'purpose' => 'shared']);
        app(RenewalScheduler::class)->tick($org);
        $f = ClientFollowup::firstOrFail();
        $operator = User::factory()->create();
        Membership::create(['organization_id' => $org->id, 'user_id' => $operator->id, 'role' => Role::Operator, 'is_active' => true]);
        DB::table('project_accesses')->insert(['organization_id' => $org->id, 'project_id' => $project->id, 'user_id' => $operator->id]);
        $base = '/api/v1/organizations/'.$org->id.'/follow-ups/'.$f->id;
        $response = $this->actingAs($operator)->getJson($base)->assertOk()->assertJsonCount(1, 'data.impactedProjects')->assertJsonPath('data.fullScope', false)->assertJsonPath('data.draft', null)->assertJsonCount(0, 'data.attempts');
        $this->assertStringNotContainsString('Hidden client project', $response->getContent());
        $this->postJson($base.'/drafts', ['version' => $f->version])->assertNotFound();
        $this->withHeader('Idempotency-Key', 'partial-scope-01')->postJson($base.'/claim', ['version' => $f->version])->assertNotFound();
    }

    public function test_manual_start_and_draft_generation_are_versioned_idempotent_and_session_protected(): void
    {
        [$org, $owner, $service] = $this->renewalGraph(['action_owner' => 'solveit', 'expiry_date' => '2027-10-09']);
        $url = '/api/v1/organizations/'.$org->id.'/services/'.$service->id.'/follow-up';
        $this->actingAs($owner)->postJson($url, ['version' => $service->version])->assertCreated();
        $this->postJson($url, ['version' => $service->version])->assertCreated();
        $this->assertSame(1, ClientFollowup::count());
        $this->assertSame(1, TemplateDraft::count());
        $f = ClientFollowup::firstOrFail();
        $draftUrl = '/api/v1/organizations/'.$org->id.'/follow-ups/'.$f->id.'/drafts';
        $this->postJson($draftUrl, ['version' => $f->version, 'template_key' => 'TPL-04'])->assertOk();
        $this->withHeader('Idempotency-Key', 'stale-draft-after-claim')->postJson('/api/v1/organizations/'.$org->id.'/follow-ups/'.$f->id.'/claim', ['version' => $f->version])->assertOk();
        $this->postJson($draftUrl, ['version' => $f->version])->assertConflict();
        $route = app('router')->getRoutes()->match(Request::create($draftUrl, 'POST'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains(PreventRequestForgery::class, app('router')->resolveMiddleware(['web']));
        $this->assertDatabaseCount('contact_attempts', 0);
    }

    public function test_dashboard_verification_records_reviewed_reference_atomically_and_does_not_expose_it(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04T03:00:00Z'));
        [$org, $owner, $service] = $this->renewalGraph();
        app(RenewalScheduler::class)->tick($org);
        $f = ClientFollowup::firstOrFail();
        $url = '/api/v1/organizations/'.$org->id.'/follow-ups/'.$f->id.'/verify_renewal';
        $data = ['version' => $f->version, 'subscription_version' => $service->version, 'date_precision' => 'date', 'expiry_date' => '2027-10-09', 'source_timezone' => 'Asia/Jakarta', 'source' => 'manual_provider_review', 'evidence_reference' => 'evidence:fixture/provider-receipt-new', 'provider_evidence_confirmed' => false];
        $this->actingAs($owner)->withHeader('Idempotency-Key', 'reference-review-01')->postJson($url, $data)->assertUnprocessable();
        $this->assertSame(1, Evidence::count());
        $data['provider_evidence_confirmed'] = true;
        $this->withHeader('Idempotency-Key', 'reference-review-02')->postJson($url, $data)->assertOk();
        $this->assertSame(2, Evidence::count());
        $this->assertDatabaseHas('evidences', ['secure_reference' => $data['evidence_reference'], 'verified_by_user_id' => $owner->id]);
        $this->getJson('/api/v1/organizations/'.$org->id.'/follow-ups/'.$f->id)->assertOk()->assertDontSee($data['evidence_reference']);
        $this->postJson('/api/v1/organizations/'.$org->id.'/follow-ups/'.$f->id.'/drafts', ['version' => $f->fresh()->version, 'template_key' => 'TPL-01'])->assertOk()->assertJsonPath('data.draft_status', 'blocked_missing_data');
    }
}
