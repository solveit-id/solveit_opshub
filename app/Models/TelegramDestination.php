<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;

class TelegramDestination extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'all_projects' => 'boolean', 'owner_route' => 'boolean', 'project_ids' => 'array', 'severities' => 'array', 'scope_confirmed_at' => 'immutable_datetime'];
    }
}
