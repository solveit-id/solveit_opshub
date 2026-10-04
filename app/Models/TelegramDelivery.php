<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;

class TelegramDelivery extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['project_ids' => 'array', 'event_ids' => 'array', 'reply_markup' => 'array', 'available_at' => 'immutable_datetime', 'first_attempt_at' => 'immutable_datetime', 'last_attempt_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime', 'fake' => 'boolean'];
    }
}
