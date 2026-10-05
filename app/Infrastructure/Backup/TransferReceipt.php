<?php

namespace App\Infrastructure\Backup;

final readonly class TransferReceipt
{
    public function __construct(public string $objectReference, public string $version, public int $bytes, public string $sha256, public bool $independent, public bool $fake = false)
    {
        if (! preg_match('/^store:[a-zA-Z0-9_-]{1,120}$/D', $objectReference) || ! preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', $version)
            || $bytes < 0 || ! preg_match('/^[0-9a-f]{64}$/D', $sha256)) {
            throw new \InvalidArgumentException('Invalid private transfer receipt.');
        }
    }
}
