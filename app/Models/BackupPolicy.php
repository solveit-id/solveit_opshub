<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;

class BackupPolicy extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['configuration'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'enabled' => 'boolean', 'approved_at' => 'immutable_datetime'];
    }
}
