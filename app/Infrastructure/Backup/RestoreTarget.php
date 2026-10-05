<?php

namespace App\Infrastructure\Backup;

use App\Models\BackupRestoreDrill;

interface RestoreTarget
{
    /** Explicit isolated/production/configured/fake facts; label alone is not isolation proof. */
    public function inspect(BackupRestoreDrill $drill): array;

    public function begin(BackupRestoreDrill $drill): void;

    public function entry(array $entry): void;

    public function write(string $chunk): void;

    public function endEntry(array $entry): void;

    /** Verify restored bytes/files against authenticated manifest; no unsupported database/app health claim. */
    public function check(array $manifest): array;

    /** Discard isolated plaintext workspace on both failure and completion. */
    public function discard(): void;

    /** Explicit operator reconciliation of the recorded isolated workspace, no retry of restore. */
    public function reconcile(BackupRestoreDrill $drill): bool;
}
