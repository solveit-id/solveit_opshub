<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ContactAttempt extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Contact attempts are append-only. Record corrections as additional evidence.'));
        static::deleting(fn () => throw new LogicException('Contact attempts are append-only.'));
    }
}
