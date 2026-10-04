<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;

class TelegramCallbackReference extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['reference_hash'];

    protected function casts(): array
    {
        return ['project_ids' => 'array', 'expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime'];
    }
}
