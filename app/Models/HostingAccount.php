<?php

namespace App\Models;

use App\Application\ClientTemplates\DraftInvalidator;
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

    protected static function booted(): void
    {
        static::updated(function (self $account) {
            $ids = ServiceSubscription::where('organization_id', $account->organization_id)->where('resource_asset_id', $account->asset_id)->pluck('id')->all();
            app(DraftInvalidator::class)->services($ids);
        });
    }
}
