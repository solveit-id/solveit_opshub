<?php

namespace App\Infrastructure\Testing;

use App\Infrastructure\Backup\TransferClock;

class AdvancingTransferClock implements TransferClock
{
    public float $elapsed = 0;

    public function __construct()
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Transfer clock fixture is test-only.');
        }
    }

    public function seconds(): float
    {
        return $this->elapsed;
    }

    public function wait(float $seconds): void
    {
        $this->elapsed += $seconds;
    }
}
