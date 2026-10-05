<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;

class ManagementAuthorization extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $fillable = [
        'organization_id',
        'resource_type',
        'resource_id',
        'allowed_action_classes',
        'authorizer_label',
        'evidence_id',
        'valid_until',
    ];

    protected function casts(): array
    {
        return ['allowed_action_classes' => 'array', 'valid_until' => 'datetime'];
    }
}
