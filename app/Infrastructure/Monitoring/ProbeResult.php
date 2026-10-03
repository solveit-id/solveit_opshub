<?php

namespace App\Infrastructure\Monitoring;

readonly class ProbeResult
{
    public function __construct(public string $outcome, public string $reason, public array $evidence = [], public string $source = 'public_probe', public bool $fake = false) {}
}
