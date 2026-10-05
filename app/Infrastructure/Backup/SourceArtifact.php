<?php

namespace App\Infrastructure\Backup;

final readonly class SourceArtifact
{
    public function __construct(public string $runReference, public string $providerReference, public string $locator,
        public int $bytes, public int $mtime, public bool $completionProven, public bool $ownershipProven = false)
    {
        if (! preg_match('/^[0-9a-f-]{36}$/D', $runReference) || ! preg_match('/^[1-9][0-9]{0,9}$/D', $providerReference)
            || $locator === '' || strlen($locator) > 512 || preg_match('/[\\\\\x00-\x1f\x7f%]/', $locator)
            || str_starts_with($locator, '/') || array_intersect(explode('/', $locator), ['.', '..', '']) || $bytes < 0 || $mtime < 0) {
            throw new \InvalidArgumentException('Invalid private source locator.');
        }
    }
}
