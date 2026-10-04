<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RenewalCycle extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expiry_snapshot' => 'array', 'opened_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ServiceSubscription::class, 'service_subscription_id');
    }

    public function followup(): HasOne
    {
        return $this->hasOne(ClientFollowup::class);
    }
}
