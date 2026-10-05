<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;

class BackupRestoreDrill extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id', 'active_artifact_id'];

    protected $hidden = ['target_reference', 'workspace_reference', 'lease_owner'];

    protected function casts(): array
    {
        return ['checks' => 'array', 'evidence' => 'array', 'fake' => 'boolean', 'leased_until' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
