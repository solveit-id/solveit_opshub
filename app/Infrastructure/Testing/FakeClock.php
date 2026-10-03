<?php

namespace App\Infrastructure\Testing;

use Carbon\CarbonImmutable;
use DateTimeInterface;

class FakeClock
{
    private CarbonImmutable $currentTime;

    public function __construct(DateTimeInterface $currentTime)
    {
        $this->currentTime = CarbonImmutable::instance($currentTime);
    }

    public function now(): CarbonImmutable
    {
        return $this->currentTime;
    }

    public function advanceMinutes(int $minutes): void
    {
        $this->currentTime = $this->currentTime->addMinutes($minutes);
    }
}
