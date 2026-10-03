<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'client_id',
        'code',
        'name',
        'lifecycle',
        'criticality',
        'internal_pic_user_id',
        'stack_tags',
        'notes',
        'version',
    ];

    protected function casts(): array
    {
        return ['stack_tags' => 'array'];
    }
}
