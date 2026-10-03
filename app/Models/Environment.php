<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Environment extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'project_id', 'kind', 'display_name', 'version'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assetUsages(): HasMany
    {
        return $this->hasMany(AssetUsage::class);
    }
}
