<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Monitor extends Model
{
    use BelongsToOrganization;
    use StoresUtcDates;

    protected $guarded = ['id'];

    protected $attributes = ['enabled' => true, 'state' => 'unknown', 'failures' => 0, 'successes' => 0];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'enabled' => 'boolean', 'next_due_at' => 'immutable_datetime', 'leased_until' => 'immutable_datetime', 'first_failed_at' => 'immutable_datetime', 'first_recovery_at' => 'immutable_datetime', 'last_evaluated_slot' => 'immutable_datetime'];
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'monitor_usages')->withPivot('environment_id');
    }
}
