<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class MessageTemplateVersion extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mandatory_variables' => 'array', 'allowed_variables' => 'array', 'published_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Published template is immutable.'));
        static::deleting(fn () => throw new LogicException('Published template is immutable.'));
    }
}
