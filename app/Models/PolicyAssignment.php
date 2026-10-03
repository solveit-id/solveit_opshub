<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyAssignment extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'policy_version_id', 'resource_type', 'resource_id', 'overrides', 'is_active'];

    protected function casts(): array
    {
        return ['overrides' => 'array', 'is_active' => 'boolean'];
    }

    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class);
    }
}
