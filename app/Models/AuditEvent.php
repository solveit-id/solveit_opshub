<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditEvent extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'actor_user_id',
        'actor_type',
        'action',
        'object_type',
        'object_id',
        'permission_context',
        'before',
        'after',
        'reason',
        'outcome',
        'request_id',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'permission_context' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit events are append-only.'));
    }
}
