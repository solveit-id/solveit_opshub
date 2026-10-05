<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;

class BackupInternalIncident extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['deduplication_key'];

    protected function casts(): array
    {
        return ['impacted_project_ids' => 'array', 'opened_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }
}
