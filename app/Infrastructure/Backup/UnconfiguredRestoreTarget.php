<?php

namespace App\Infrastructure\Backup;

use App\Models\BackupRestoreDrill;

class UnconfiguredRestoreTarget implements RestoreTarget
{
    public function inspect(BackupRestoreDrill $drill): array
    {
        return ['configured' => false, 'isolated' => false, 'production' => false, 'fake' => false];
    }

    public function begin(BackupRestoreDrill $drill): void
    {
        throw new \LogicException('Isolated restore target is not configured.');
    }

    public function entry(array $entry): void
    {
        throw new \LogicException;
    }

    public function write(string $chunk): void
    {
        throw new \LogicException;
    }

    public function endEntry(array $entry): void
    {
        throw new \LogicException;
    }

    public function check(array $manifest): array
    {
        throw new \LogicException;
    }

    public function discard(): void {}

    public function reconcile(BackupRestoreDrill $drill): bool
    {
        return false;
    }
}
