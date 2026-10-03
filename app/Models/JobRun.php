<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class JobRun extends Model
{
    use BelongsToOrganization;

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
    ];

    protected function casts(): array
    {
        return ['scheduled_slot' => 'datetime', 'leased_until' => 'datetime'];
    }
}
