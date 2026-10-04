<?php

namespace App\Models;

use App\Application\ClientTemplates\DraftInvalidator;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceSubscription extends Model
{
    use BelongsToOrganization;
    use StoresUtcDates;

    protected $fillable = [
        'organization_id',
        'asset_id',
        'billing_party',
        'billing_due_at',
        'paying_party',
        'action_owner',
        'expires_at',
        'expiry_date',
        'date_precision',
        'source_timezone',
        'source',
        'evidence_id',
        'reminder_policy',
        'version',
        'service_name', 'service_kind', 'resource_asset_id', 'renew_by', 'payment_status',
    ];

    protected function casts(): array
    {
        return [
            'billing_due_at' => 'datetime',
            'expires_at' => 'datetime',
            'expiry_date' => 'date',
            'reminder_policy' => 'array',
            'renew_by' => 'immutable_datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class);
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(RenewalCycle::class);
    }

    protected static function booted(): void
    {
        static::updated(fn (self $service) => app(DraftInvalidator::class)->services([$service->id]));
    }
}
