<?php

namespace App\Models;

use App\Infrastructure\Connectors\ConnectorConfig;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtcDates;
use Illuminate\Database\Eloquent\Model;

class Connector extends Model
{
    use BelongsToOrganization, StoresUtcDates;

    protected $guarded = ['id'];

    protected $hidden = ['configuration'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'writes_paused' => 'boolean', 'last_tested_at' => 'immutable_datetime'];
    }

    public function snapshot(): ConnectorConfig
    {
        return new ConnectorConfig($this->organization_id, $this->hosting_account_id, $this->kind,
            $this->configuration['endpoint'], $this->configuration['account_identifier'], $this->configuration['secret_reference'],
            $this->configuration['roots'] ?? [], $this->configuration['host_fingerprint'] ?? null, $this->version);
    }
}
