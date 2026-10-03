<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

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
}
