<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Asset extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'kind',
        'canonical_identity',
        'responsibility',
        'owner_user_id',
        'source',
        'evidence_id',
        'verified_at',
        'notes',
        'version',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function usages(): HasMany
    {
        return $this->hasMany(AssetUsage::class);
    }

    public function hostingAccount(): HasOne
    {
        return $this->hasOne(HostingAccount::class);
    }

    public function serviceSubscription(): HasOne
    {
        return $this->hasOne(ServiceSubscription::class);
    }
}
