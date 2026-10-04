<?php

namespace App\Infrastructure\Connectors;

use InvalidArgumentException;

/** An operation identity, never a path, token, shell command or provider request body. */
final readonly class BackupRequest
{
    public function __construct(public string $runReference, public Capability $capability)
    {
        if (! preg_match('/^opshub-[a-f0-9-]{36}$/D', $runReference)
            || ! in_array($capability, [Capability::FullBackupTrigger, Capability::FileBackup], true)) {
            throw new InvalidArgumentException('Invalid backup operation reference or scope.');
        }
    }
}
