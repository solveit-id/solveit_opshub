<?php

namespace Tests\Feature;

use App\Application\ClientTemplates\DraftGenerator;
use App\Application\RenewalFollowups\FollowupWorkflow;
use App\Application\RenewalFollowups\RenewalScheduler;
use App\Domain\IdentityAccess\Role;
use App\Models\ClientFollowup;
use App\Models\ContactAttempt;
use App\Models\Evidence;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\RenewalCycle;
use App\Models\RenewalReminder;
use App\Models\TemplateDraft;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\RenewalFixture;
use Tests\TestCase;

class FollowupWorkflowTest extends TestCase
{
    use RefreshDatabase, RenewalFixture;

    private function graph(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04T03:00:00Z'));
        $graph = $this->renewalGraph();
        app(RenewalScheduler::class)->tick($graph[0], CarbonImmutable::now('UTC'));

        return $graph;
    }

    public function test_current_draft_contact_is_explicit_append_only_and_idempotent(): void
    {
        [$org, $owner, $service, $project, $contact] = $this->graph();
        $f = ClientFollowup::firstOrFail();
        $draft = app(DraftGenerator::class)->generate($org, $f, actor: $owner);
        $this->assertSame('open', $f->fresh()->state);
        $this->assertSame(0, ContactAttempt::count());
        $data = ['version' => $f->version, 'template_draft_id' => $draft->id, 'contact_id' => $contact->id, 'sent_at' => '2026-10-04T02:59:00Z', 'manual_channel' => 'whatsapp_manual', 'next_followup_at' => '2026-10-05T03:00:00Z'];
        $url = '/api/v1/organizations/'.$org->id.'/follow-ups/'.$f->id.'/contact';
        $this->actingAs($owner)->withHeader('Idempotency-Key', 'contact-fixture-01')->postJson($url, $data)->assertOk()->assertJsonPath('data.state', 'contacted');
        $this->postJson($url, $data)->assertOk();
        $this->assertSame(1, ContactAttempt::count());
        $attempt = ContactAttempt::firstOrFail();
        $this->assertSame($draft->rendered_body, $attempt->sent_body);
        $this->assertSame($owner->id, $attempt->actor_user_id);
        $this->assertSame($contact->id, $attempt->contact_id);
        $this->withHeader('Idempotency-Key', 'contact-fixture-02')->postJson($url, $data)->assertConflict();
        $this->assertDatabaseHas('audit_events', ['action' => 'followup.contact_recorded']);
    }

    public function test_stale_draft_and_future_contact_are_rejected_without_history(): void
    {
        [$org, $owner, $service, $project, $contact] = $this->graph();
        $f = ClientFollowup::firstOrFail();
        $draft = TemplateDraft::firstOrFail();
        $contact->update(['name' => 'Ibu Changed']);
        $url = '/api/v1/organizations/'.$org->id.'/follow-ups/'.$f->id.'/contact';
        $data = ['version' => $f->version, 'template_draft_id' => $draft->id, 'contact_id' => $contact->id, 'sent_at' => '2026-10-04T02:59:00Z', 'manual_channel' => 'manual', 'next_followup_at' => '2026-10-05T03:00:00Z'];
        $this->actingAs($owner)->withHeader('Idempotency-Key', 'stale-contact-01')->postJson($url, $data)->assertConflict();
        $draft = app(DraftGenerator::class)->generate($org, $f, actor: $owner);
        $this->withHeader('Idempotency-Key', 'future-contact-01')->postJson($url, [...$data, 'template_draft_id' => $draft->id, 'sent_at' => '2026-10-06T00:00:00Z'])->assertUnprocessable();
        $this->assertSame(0, ContactAttempt::count());
        $this->assertSame('open', $f->fresh()->state);
    }

    public function test_waiting_confirmation_assignment_and_reopen_are_separate_from_verification(): void
    {
        [$org, $owner, $service] = $this->graph();
        $f = ClientFollowup::firstOrFail();
        try {
            app(FollowupWorkflow::class)->act($org, $owner, $f, 'waiting_client', ['version' => $f->version]);
            $this->fail('Waiting without deadline.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('next_followup_at', $e->errors());
        }
        $f = app(FollowupWorkflow::class)->act($org, $owner, $f, 'claim', ['version' => $f->version]);
        $this->assertSame($owner->id, $f->assignee_user_id);
        $f = app(FollowupWorkflow::class)->act($org, $owner, $f, 'waiting_client', ['version' => $f->version, 'next_followup_at' => '2026-10-05T03:00:00Z', 'blocker' => 'Menunggu keputusan', 'client_commitment' => 'Konfirmasi besok']);
        $f = app(FollowupWorkflow::class)->act($org, $owner, $f, 'client_confirmed', ['version' => $f->version, 'response_summary' => 'Client melaporkan sudah membayar']);
        $this->assertSame('client_confirmed', $f->state);
        $this->assertSame('active', $f->cycle->state);
        $this->assertSame('unknown', $service->fresh()->payment_status);
        $f = app(FollowupWorkflow::class)->act($org, $owner, $f, 'cancel', ['version' => $f->version, 'reason' => 'Keputusan penghentian dicatat']);
        $this->assertSame('cancelled', $f->state);
        $this->assertSame(0, RenewalReminder::where('state', 'pending')->count());
        $f = app(FollowupWorkflow::class)->act($org, $owner, $f, 'reopen', ['version' => $f->version, 'reason' => 'Keputusan client berubah']);
        $this->assertSame('open', $f->state);
    }

    public function test_verified_later_future_expiry_opens_one_new_cycle_and_cancels_old_pending(): void
    {
        [$org, $owner, $service] = $this->graph();
        $f = ClientFollowup::firstOrFail();
        $oldCycle = $f->cycle;
        $evidence = Evidence::create(['organization_id' => $org->id, 'kind' => 'provider_expiry', 'source' => 'fixture_new_provider_period', 'verified_by_user_id' => $owner->id, 'verified_at' => now()]);
        $url = '/api/v1/organizations/'.$org->id.'/follow-ups/'.$f->id.'/verify_renewal';
        $data = ['version' => $f->version, 'subscription_version' => $service->version, 'date_precision' => 'date', 'expiry_date' => '2027-10-09', 'source_timezone' => 'Asia/Jakarta', 'source' => 'fixture_new_provider_period', 'evidence_id' => $evidence->id];
        $this->actingAs($owner)->withHeader('Idempotency-Key', 'verify-renewal-01')->postJson($url, $data)->assertOk()->assertJsonPath('data.state', 'resolved');
        $this->postJson($url, $data)->assertOk();
        $this->assertSame(2, RenewalCycle::count());
        $this->assertSame(1, RenewalCycle::whereNotNull('active_subscription_id')->count());
        $this->assertSame('verified', $oldCycle->fresh()->state);
        $this->assertSame($owner->id, $oldCycle->fresh()->verified_by_user_id);
        $this->assertSame($evidence->id, $oldCycle->fresh()->evidence_id);
        $this->assertSame(0, RenewalReminder::where('renewal_cycle_id', $oldCycle->id)->where('state', 'pending')->count());
        $this->assertSame(0, OutboxEvent::where('event_type', 'renewal.reminder')->where('status', 'pending')->count());
        $this->assertNull($service->fresh()->renew_by);
        $this->assertSame('unknown', $service->fresh()->payment_status);
        $this->assertSame('ready', TemplateDraft::where('template_key', 'TPL-10')->firstOrFail()->draft_status);
        $this->withHeader('Idempotency-Key', 'verify-renewal-02')->postJson($url, $data)->assertConflict();
    }

    public function test_payment_evidence_old_or_nonfuture_expiry_cannot_close_renewal(): void
    {
        [$org, $owner, $service] = $this->graph();
        $f = ClientFollowup::firstOrFail();
        $payment = Evidence::create(['organization_id' => $org->id, 'kind' => 'payment', 'source' => 'fixture_invoice', 'verified_by_user_id' => $owner->id, 'verified_at' => now()]);
        $provider = Evidence::create(['organization_id' => $org->id, 'kind' => 'provider_expiry', 'source' => 'fixture_provider', 'verified_by_user_id' => $owner->id, 'verified_at' => now()]);
        foreach ([[$payment->id, '2027-10-09'], [$provider->id, '2026-10-09'], [$provider->id, '2026-09-01'], [$service->evidence_id, '2027-10-09']] as [$evidence, $date]) {
            try {
                app(FollowupWorkflow::class)->act($org, $owner, $f, 'verify_renewal', ['version' => $f->version, 'subscription_version' => $service->version, 'date_precision' => 'date', 'expiry_date' => $date, 'source_timezone' => 'Asia/Jakarta', 'source' => 'fixture_provider', 'evidence_id' => $evidence]);
                $this->fail('Invalid renewal accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('expiry', $e->errors());
            }
        }
        $this->assertSame(1, RenewalCycle::count());
        $this->assertSame('open', $f->fresh()->state);
    }

    public function test_role_and_cross_organization_scope_are_rechecked_for_mutations(): void
    {
        [$org, $owner] = $this->graph();
        $f = ClientFollowup::firstOrFail();
        $url = '/api/v1/organizations/'.$org->id.'/follow-ups/'.$f->id.'/claim';
        $this->actingAs($owner)->withHeader('Idempotency-Key', 'claim-fixture-01')->postJson($url, ['version' => $f->version])->assertOk();
        Membership::where('user_id', $owner->id)->update(['role' => Role::Viewer->value]);
        $this->postJson($url, ['version' => $f->version])->assertForbidden();
        $other = Organization::create(['name' => 'Other', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        Membership::create(['organization_id' => $other->id, 'user_id' => $owner->id, 'role' => Role::Owner, 'is_active' => true]);
        $this->postJson('/api/v1/organizations/'.$other->id.'/follow-ups/'.$f->id.'/claim', ['version' => $f->version])->assertNotFound();
    }
}
