<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'event_id',
        'organization_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'aggregate_version',
        'payload',
        'status',
        'available_at',
        'dispatched_at',
        'attempts',
        'correlation_id',
        'causation_id',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'datetime', 'dispatched_at' => 'datetime'];
    }
}
