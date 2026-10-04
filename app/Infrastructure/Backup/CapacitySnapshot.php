<?php

namespace App\Infrastructure\Backup;

use Carbon\CarbonImmutable;

final readonly class CapacitySnapshot
{
    public CarbonImmutable $observedAt;

    public function __construct(public ?int $estimatedBytes, public ?int $sourceFreeBytes, public ?int $storageFreeBytes,
        public bool $independent, public bool $fake = false, ?CarbonImmutable $observedAt = null)
    {
        foreach ([$estimatedBytes, $sourceFreeBytes, $storageFreeBytes] as $bytes) {
            if ($bytes !== null && ($bytes < 0 || $bytes > PHP_INT_MAX / 2)) {
                throw new \InvalidArgumentException('Invalid capacity evidence.');
            }
        }
        $this->observedAt = ($observedAt ?? CarbonImmutable::now('UTC'))->utc();
    }
}
