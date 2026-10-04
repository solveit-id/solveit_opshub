<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ConnectorAssessment extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'retryable' => 'boolean', 'observed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Connector assessment history is append-only.'));
        static::deleting(fn () => throw new LogicException('Connector assessment history is append-only.'));
    }
}
