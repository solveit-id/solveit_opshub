<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Policy extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name', 'kind', 'status', 'draft_configuration', 'version'];

    protected function casts(): array
    {
        return ['draft_configuration' => 'array'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class);
    }
}
