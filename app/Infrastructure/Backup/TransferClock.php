<?php

namespace App\Infrastructure\Backup;

interface TransferClock
{
    public function seconds(): float;

    public function wait(float $seconds): void;
}
