<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientFollowup extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['template_context' => 'array', 'next_followup_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime', 'snoozed_until' => 'immutable_datetime'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(RenewalCycle::class, 'renewal_cycle_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ContactAttempt::class);
    }

    public function overdue(?CarbonImmutable $now = null): bool
    {
        return ! in_array($this->state, ['resolved', 'cancelled'], true)
            && $this->next_followup_at !== null && $this->next_followup_at->lessThanOrEqualTo($now ?? CarbonImmutable::now('UTC'));
    }
}
