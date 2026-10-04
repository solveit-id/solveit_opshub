<?php

namespace App\Models;

use App\Application\ClientTemplates\DraftInvalidator;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'client_id',
        'name',
        'contact_value',
        'purpose',
        'preferred_manual_channel',
        'verified_at',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected static function booted(): void
    {
        static::updated(fn (self $contact) => app(DraftInvalidator::class)->contact($contact->id));
    }
}
