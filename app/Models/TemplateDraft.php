<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class TemplateDraft extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['variables_snapshot' => 'array', 'blocked_reasons' => 'array', 'generated_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $draft) {
            if (array_diff(array_keys($draft->getDirty()), ['draft_status', 'updated_at']) !== []) {
                throw new LogicException('Draft snapshot is immutable; generate a new draft.');
            }
        });
        static::deleting(fn () => throw new LogicException('Draft history is immutable.'));
    }
}
