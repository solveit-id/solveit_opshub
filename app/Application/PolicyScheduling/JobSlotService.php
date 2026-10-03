<?php

namespace App\Application\PolicyScheduling;

use App\Models\JobRun;
use App\Models\Organization;
use Carbon\CarbonInterface;

class JobSlotService
{
    public function reserve(
        Organization $organization,
        string $kind,
        CarbonInterface $scheduledSlot,
        string $resourceType = 'organization',
        int $resourceId = 0,
        int $policyVersionId = 0,
        ?string $correlationId = null,
    ): JobRun {
        return JobRun::firstOrCreate([
            'organization_id' => $organization->id,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'kind' => $kind,
            'scheduled_slot' => $scheduledSlot->utc(),
            'policy_version_id' => $policyVersionId,
        ], [
            'state' => 'queued',
            'correlation_id' => $correlationId,
        ]);
    }
}
