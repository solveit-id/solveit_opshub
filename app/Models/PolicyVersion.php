<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PolicyVersion extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'policy_id', 'version', 'configuration', 'published_at', 'published_by_user_id'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Published policy versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Published policy versions are immutable.'));
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
