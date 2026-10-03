<?php

namespace App\Application\ActivityEvidence;

use App\Infrastructure\Security\SensitiveDataRedactor;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;

class AuditWriter
{
    public function __construct(private readonly SensitiveDataRedactor $redactor) {}

    public function write(
        Organization $organization,
        string $action,
        string $objectType,
        string|int $objectId,
        string $outcome,
        ?User $actor = null,
        array $before = [],
        array $after = [],
        array $permissionContext = [],
        ?string $reason = null,
        ?string $requestId = null,
        ?string $correlationId = null,
    ): AuditEvent {
        return AuditEvent::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor === null ? 'system' : 'human',
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => (string) $objectId,
            'permission_context' => $this->redactor->redactArray($permissionContext),
            'before' => $this->redactor->redactArray($before),
            'after' => $this->redactor->redactArray($after),
            'reason' => $reason === null ? null : $this->redactor->redact($reason),
            'outcome' => $outcome,
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
        ]);
    }
}
