<?php

namespace App\Domain\IdentityAccess;

enum Role: string
{
    case Owner = 'OWNER';
    case Operator = 'OPERATOR';
    case Operations = 'OPERATIONS';
    case Viewer = 'VIEWER';

    public function can(string $ability): bool
    {
        if ($this === self::Owner) {
            return true;
        }

        return match ($ability) {
            'organization.read', 'project.read' => true,
            'registry.manage', 'connector.manage', 'monitor.run', 'backup.run' => $this === self::Operator,
            'renewal.manage', 'followup.manage' => $this === self::Operator || $this === self::Operations,
            default => false,
        };
    }
}
