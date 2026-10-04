<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;

class ConnectorTestRun extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id', 'active_connector_id'];

    protected $hidden = ['candidate_reference', 'revoke_reference', 'idempotency_key', 'request_digest', 'lease_owner'];

    protected function casts(): array
    {
        return ['leased_until' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'fake' => 'boolean'];
    }
}
