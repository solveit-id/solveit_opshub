<?php

namespace Tests\Feature;

use App\Application\RenewalFollowups\Expiry;
use App\Application\RenewalFollowups\SubscriptionRegistry;
use App\Models\Asset;
use App\Models\ClientFollowup;
use App\Models\ContactAttempt;
use App\Models\RenewalCycle;
use App\Models\ServiceSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Support\RenewalFixture;
use Tests\TestCase;

class RenewalModelTest extends TestCase
{
    use RefreshDatabase, RenewalFixture;

    public function test_date_precision_utc_instant_billing_and_unknown_are_separate(): void
    {
        [, , $service] = $this->renewalGraph(['billing_due_at' => '2026-10-01T00:00:00+07:00', 'payment_status' => 'reported_paid']);
        $this->assertSame('2026-10-09T16:59:59+00:00', app(Expiry::class)->instant(app(Expiry::class)->snapshot($service))->toIso8601String());
        $this->assertSame('2026-09-30T17:00:00+00:00', $service->billing_due_at->toIso8601String());
        $this->assertNull($service->expires_at);
        $this->assertSame('active', $service->cycles()->sole()->state);
        $instant = app(Expiry::class)->normalize(['date_precision' => 'instant', 'expires_at' => '2026-10-09T12:00:00+07:00']);
        $this->assertSame('2026-10-09 05:00:00', $instant['expires_at']);
        $this->assertNull(app(Expiry::class)->instant(['date_precision' => 'unknown']));
    }

    public function test_cycle_and_primary_followup_have_database_uniqueness(): void
    {
        [, , $service] = $this->renewalGraph();
        $first = app(SubscriptionRegistry::class)->cycle($service);
        $this->assertSame($first->id, app(SubscriptionRegistry::class)->cycle($service)->id);
        ClientFollowup::create(['organization_id' => $service->organization_id, 'renewal_cycle_id' => $first->id]);
        $this->expectException(QueryException::class);
        ClientFollowup::create(['organization_id' => $service->organization_id, 'renewal_cycle_id' => $first->id]);
    }

    public function test_stale_metadata_conflicts_and_correction_does_not_verify_renewal(): void
    {
        [$org, $owner, $service, , , $data] = $this->renewalGraph();
        $endpoint = "/api/v1/organizations/{$org->id}/registry/service-subscriptions/{$service->id}";
        $this->actingAs($owner)->patchJson($endpoint, [...$data, 'version' => 99])->assertConflict();
        $this->patchJson($endpoint, [...$data, 'version' => 1, 'expiry_date' => '2027-10-09'])->assertUnprocessable();
        $this->patchJson($endpoint, [...$data, 'version' => 1, 'expiry_date' => '2027-10-09', 'correction_reason' => 'Provider corrected metadata'])->assertOk();
        $this->assertSame('active', RenewalCycle::sole()->state);
        $this->assertNull(RenewalCycle::sole()->verified_at);
        $this->assertSame(1, RenewalCycle::count());
        $this->patchJson($endpoint, [...$data, 'version' => 1])->assertConflict();
        $this->actingAs(User::factory()->create())->patchJson($endpoint, [...$data, 'version' => 2])->assertForbidden();
    }

    public function test_contact_history_is_append_only_and_overdue_is_derived(): void
    {
        [$org, $owner, $service, , $contact] = $this->renewalGraph();
        $followup = ClientFollowup::create(['organization_id' => $org->id, 'renewal_cycle_id' => $service->cycles()->sole()->id, 'state' => 'waiting_client', 'next_followup_at' => '2026-10-04 00:00:00']);
        $this->assertTrue($followup->overdue(CarbonImmutable::parse('2026-10-04T01:00:00Z')));
        $this->assertSame('waiting_client', $followup->state);
        $attempt = ContactAttempt::create(['organization_id' => $org->id, 'client_followup_id' => $followup->id, 'actor_user_id' => $owner->id, 'contact_id' => $contact->id, 'sent_at' => now(), 'manual_channel' => 'manual', 'template_draft_id' => 1, 'draft_version' => 1, 'sent_body' => 'Fixture historical body', 'recorded_at' => now()]);
        $this->expectException(LogicException::class);
        $attempt->update(['sent_body' => 'Rewritten']);
    }

    public function test_database_rejects_a_second_active_cycle_even_with_a_new_sequence(): void
    {
        [, , $service] = $this->renewalGraph();
        $cycle = $service->cycles()->sole();
        $this->expectException(QueryException::class);
        RenewalCycle::create([...$cycle->only(['organization_id', 'service_subscription_id', 'active_subscription_id', 'expiry_snapshot', 'opened_at']), 'sequence' => 2]);
    }

    public function test_canonical_resource_duplicates_and_cross_organization_evidence_are_denied(): void
    {
        [$org, $owner, $service, , , $data] = $this->renewalGraph();
        $endpoint = "/api/v1/organizations/{$org->id}/registry/service-subscriptions";
        $this->actingAs($owner)->postJson($endpoint, $data)->assertUnprocessable();
        $resource = Asset::create(['organization_id' => $org->id, 'kind' => 'hosting_account', 'canonical_identity' => 'shared-fixture']);
        $this->patchJson($endpoint.'/'.$service->id, [...$data, 'version' => 1, 'resource_asset_id' => $resource->id])->assertOk();
        $second = Asset::create(['organization_id' => $org->id, 'kind' => 'service_subscription', 'canonical_identity' => 'duplicate-fixture']);
        $this->postJson($endpoint, [...$data, 'asset_id' => $second->id, 'resource_asset_id' => $resource->id])->assertUnprocessable();
        [$otherOrg, , $otherService] = $this->renewalGraph();
        $this->patchJson($endpoint.'/'.$service->id, [...$data, 'version' => 2, 'evidence_id' => $otherService->evidence_id, 'correction_reason' => 'Cross org denied'])->assertNotFound();
        $this->assertSame(1, ServiceSubscription::forOrganization($org)->count());
    }
}
