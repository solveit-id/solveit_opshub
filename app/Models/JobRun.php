<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;

class JobRun extends Model
{
    use BelongsToOrganization;
    use StoresUtcDates;

    protected $fillable = [
        'organization_id',
        'resource_type',
        'resource_id',
        'kind',
        'scheduled_slot',
        'policy_version_id',
        'state',
        'attempts',
        'lease_owner',
        'leased_until',
        'correlation_id',
        'last_error_code',
        'queue_enqueued_at',
        'missed_slots',
    ];

    protected function casts(): array
    {
        return ['scheduled_slot' => 'immutable_datetime', 'leased_until' => 'immutable_datetime', 'queue_enqueued_at' => 'immutable_datetime'];
    }
}
