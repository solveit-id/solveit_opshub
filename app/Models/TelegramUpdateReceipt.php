<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;

class TelegramUpdateReceipt extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['encrypted_payload'];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];
    }
}
