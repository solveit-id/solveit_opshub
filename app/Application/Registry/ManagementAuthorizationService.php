<?php

namespace App\Application\Registry;

use App\Models\ManagementAuthorization;
use App\Models\Organization;

class ManagementAuthorizationService
{
    public function allows(Organization $organization, string $resourceType, int $resourceId, string $actionClass): bool
    {
        return ManagementAuthorization::query()
            ->forOrganization($organization)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->whereJsonContains('allowed_action_classes', $actionClass)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->exists();
    }
}
