<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Evidence extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'kind', 'secure_reference', 'content_digest', 'source', 'verified_by_user_id', 'verified_at', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'verified_at' => 'datetime'];
    }
}
