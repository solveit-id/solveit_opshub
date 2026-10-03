<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name', 'status', 'notes', 'version'];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
