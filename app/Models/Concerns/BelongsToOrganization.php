<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToOrganization
{
    public function scopeForOrganization(Builder $query, Organization|int $organization): Builder
    {
        return $query->where('organization_id', $organization instanceof Organization ? $organization->id : $organization);
    }
}
