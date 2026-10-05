<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;

class BackupRun extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['policy_snapshot', 'idempotency_key', 'request_digest', 'lease_owner'];

    protected function casts(): array
    {
        return ['policy_snapshot' => 'array', 'impacted_project_ids' => 'array', 'preflight_evidence' => 'array', 'fake' => 'boolean', 'source_next_at' => 'immutable_datetime', 'leased_until' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
