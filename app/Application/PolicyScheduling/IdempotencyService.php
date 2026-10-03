<?php

namespace App\Application\PolicyScheduling;

use App\Models\IdempotencyKey;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonInterface;

class IdempotencyService
{
    public function remember(
        Organization $organization,
        User $actor,
        string $action,
        string $key,
        string $requestHash,
        array $response,
        CarbonInterface $expiresAt,
    ): IdempotencyKey {
        $record = IdempotencyKey::firstOrCreate([
            'organization_id' => $organization->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'key' => $key,
        ], [
            'request_hash' => $requestHash,
            'response' => $response,
            'expires_at' => $expiresAt,
        ]);

        if ($record->request_hash !== $requestHash) {
            throw new IdempotencyConflict('The idempotency key was reused with a different request.');
        }

        return $record;
    }
}
