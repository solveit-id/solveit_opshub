<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Policy extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name', 'kind', 'status', 'version'];
}
