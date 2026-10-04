<?php

namespace App\Application\RenewalFollowups;

use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\IdentityAccess\ProjectAccess;
use App\Models\AssetUsage;
use App\Models\Organization;
use App\Models\ServiceSubscription;
use App\Models\User;
use Illuminate\Support\Collection;

class RenewalAccess
{
    public function projectIds(ServiceSubscription $service): Collection
    {
        return AssetUsage::forOrganization($service->organization_id)->whereIn('asset_id', array_filter([$service->asset_id, $service->resource_asset_id]))->pluck('project_id')->unique()->values();
    }

    public function require(User $actor, Organization $org, ServiceSubscription $service, ?string $ability = null): void
    {
        app(OrganizationAuthorizationService::class)->require($actor, $org, $ability ?? 'project.read');
        abort_unless($service->organization_id === $org->id, 404);
        if (app(ProjectAccess::class)->owner($actor, $org)) {
            return;
        }
        $ids = $this->projectIds($service);
        $visible = app(ProjectAccess::class)->query($actor, $org)->whereIn('id', $ids)->count();
        abort_unless($visible > 0 && ($ability === null || $visible === $ids->count()), 404);
    }
}
