<?php

namespace Tests\Support;

use App\Application\RenewalFollowups\SubscriptionRegistry;
use App\Domain\IdentityAccess\Role;
use App\Models\Asset;
use App\Models\AssetUsage;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Evidence;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

trait RenewalFixture
{
    protected function renewalGraph(array $expiry = []): array
    {
        $org = Organization::create(['name' => 'Renewal fictitious', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $user = User::factory()->create();
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => Role::Owner, 'is_active' => true]);
        $client = Client::create(['organization_id' => $org->id, 'name' => 'PT Fixture']);
        $contact = Contact::create(['organization_id' => $org->id, 'client_id' => $client->id, 'name' => 'Ibu Contoh', 'preferred_manual_channel' => 'manual', 'contact_value' => 'fixture-contact']);
        $project = Project::create(['organization_id' => $org->id, 'client_id' => $client->id, 'code' => 'RENEW', 'name' => 'Fixture project', 'lifecycle' => 'active', 'internal_pic_user_id' => $user->id]);
        $asset = Asset::create(['organization_id' => $org->id, 'kind' => 'service_subscription', 'canonical_identity' => 'fixture-hosting']);
        AssetUsage::create(['organization_id' => $org->id, 'asset_id' => $asset->id, 'project_id' => $project->id, 'purpose' => 'renewal']);
        $evidence = Evidence::create(['organization_id' => $org->id, 'kind' => 'provider_expiry', 'source' => 'manual_provider_fixture', 'verified_at' => now(), 'verified_by_user_id' => $user->id]);
        $data = ['asset_id' => $asset->id, 'service_name' => 'Hosting Fixture', 'service_kind' => 'hosting', 'billing_party' => 'client', 'paying_party' => 'client', 'action_owner' => 'client', 'date_precision' => 'date', 'expiry_date' => '2026-10-09', 'source_timezone' => 'Asia/Jakarta', 'source' => 'manual_provider_fixture', 'evidence_id' => $evidence->id, 'reminder_policy' => ['lead_days' => [60, 30, 14, 7, 3, 1, 0]], ...$expiry];
        $service = app(SubscriptionRegistry::class)->save($org, $user, $data);

        return [$org, $user, $service, $project, $contact, $data];
    }
}
