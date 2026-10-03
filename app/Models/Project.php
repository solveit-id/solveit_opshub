<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'client_id',
        'code',
        'name',
        'lifecycle',
        'criticality',
        'internal_pic_user_id',
        'stack_tags',
        'notes',
        'version',
    ];

    protected function casts(): array
    {
        return ['stack_tags' => 'array'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    public function assetUsages(): HasMany
    {
        return $this->hasMany(AssetUsage::class);
    }
}
