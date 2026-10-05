<?php

namespace App\Infrastructure\Backup;

class MonotonicTransferClock implements TransferClock
{
    public function seconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    public function wait(float $seconds): void
    {
        usleep((int) ceil(min(0.05, max(0, $seconds)) * 1_000_000));
    }
}
