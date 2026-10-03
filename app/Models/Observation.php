<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Observation extends Model
{
    use BelongsToOrganization;
    use StoresUtcDates;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'array', 'evidence' => 'array', 'fake' => 'boolean', 'maintenance' => 'boolean', 'retention_hold' => 'boolean', 'scheduled_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'observed_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'fresh_until' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Observations are append-only.'));
        static::deleting(fn () => throw new LogicException('Use audited retention cleanup.'));
    }
}
