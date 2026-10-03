<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostingAccount extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'asset_id',
        'provider',
        'panel_type',
        'hostname',
        'api_endpoint',
        'account_identifier',
        'quota_bytes',
        'available_access',
        'environment_roots',
        'version',
    ];

    protected function casts(): array
    {
        return ['available_access' => 'array', 'environment_roots' => 'array'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
