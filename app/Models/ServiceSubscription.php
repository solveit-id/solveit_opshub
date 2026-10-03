<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceSubscription extends Model
{
    use BelongsToOrganization;

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
    ];

    protected function casts(): array
    {
        return [
            'billing_due_at' => 'datetime',
            'expires_at' => 'datetime',
            'expiry_date' => 'date',
            'reminder_policy' => 'array',
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
}
