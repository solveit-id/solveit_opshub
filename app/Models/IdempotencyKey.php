<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'actor_user_id', 'action', 'key', 'request_hash', 'response', 'expires_at'];

    protected function casts(): array
    {
        return ['response' => 'array', 'expires_at' => 'datetime'];
    }
}
