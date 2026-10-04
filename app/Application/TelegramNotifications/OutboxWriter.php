<?php

namespace App\Application\TelegramNotifications;

use App\Infrastructure\Security\SensitiveDataRedactor;
use App\Models\Organization;
use App\Models\OutboxEvent;
use Illuminate\Support\Str;

class OutboxWriter
{
    public function __construct(private readonly SensitiveDataRedactor $redactor) {}

    public function record(
        Organization $organization,
        string $eventType,
        string $aggregateType,
        int $aggregateId,
        int $aggregateVersion,
        array $payload = [],
        ?string $correlationId = null,
        ?string $causationId = null,
    ): OutboxEvent {
        return OutboxEvent::create([
            'event_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'aggregate_version' => $aggregateVersion,
            'payload' => $this->redactor->redactArray($payload),
            'status' => 'pending',
            'available_at' => now('UTC'),
            'correlation_id' => $correlationId,
            'causation_id' => $causationId,
        ]);
    }
}
