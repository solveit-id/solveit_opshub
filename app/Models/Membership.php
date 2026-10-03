<?php

namespace App\Models;

use App\Domain\IdentityAccess\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'role', 'extra_permissions', 'is_active'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'extra_permissions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
