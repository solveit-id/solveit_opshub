<?php

namespace App\Infrastructure\Backup;

interface BackupKeyResolver
{
    public function reference(int $organizationId, string $destination): ?string;

    public function resolve(string $reference): string;
}
