<?php

namespace App\Infrastructure\Backup;

/** Single-use authorization consumed by a configured adapter immediately before deletion. */
final class BackupDeletionPermit
{
    private bool $authorized = false;

    public function __construct(private readonly \Closure $guard) {}

    public function authorize(): void
    {
        if ($this->authorized) {
            throw new \LogicException('Deletion authorization is single-use.');
        }
        ($this->guard)();
        $this->authorized = true;
    }

    public function authorized(): bool
    {
        return $this->authorized;
    }
}
