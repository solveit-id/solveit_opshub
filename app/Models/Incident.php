<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Incident extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['first_failed_at' => 'immutable_datetime', 'confirmed_down_at' => 'immutable_datetime', 'last_failed_at' => 'immutable_datetime', 'first_recovery_sample_at' => 'immutable_datetime', 'confirmed_recovered_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime', 'alert_pending' => 'boolean', 'flapping' => 'boolean'];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function observations(): BelongsToMany
    {
        return $this->belongsToMany(Observation::class, 'incident_observations');
    }
}
