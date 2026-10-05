<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;

class BackupSourceOperation extends Model
{
    use StoresUtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['provider_reference', 'artifact_locator', 'stable_fingerprint'];

    protected function casts(): array
    {
        return ['artifact_locator' => 'array', 'stable_since' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'deadline_at' => 'immutable_datetime'];
    }
}
