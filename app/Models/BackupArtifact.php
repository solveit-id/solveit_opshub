<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;

class BackupArtifact extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['object_reference', 'object_version', 'key_reference', 'manifest', 'sha256', 'encrypted_sha256', 'delete_lease', 'source_cleanup_lease'];

    protected function casts(): array
    {
        return ['environment_ids' => 'array', 'manifest' => 'array', 'coverage_scopes' => 'array', 'fake' => 'boolean', 'legal_hold' => 'boolean',
            'restore_pending' => 'boolean', 'source_observed_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime', 'delete_leased_until' => 'immutable_datetime'];
    }

    public function context(): string
    {
        return json_encode([$this->organization_id, $this->hosting_account_id, $this->backup_run_id, $this->artifact_reference, $this->object_version], JSON_THROW_ON_ERROR);
    }
}
