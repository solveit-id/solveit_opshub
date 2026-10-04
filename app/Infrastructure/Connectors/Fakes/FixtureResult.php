<?php

namespace App\Infrastructure\Connectors\Fakes;

/** Legacy M0 probe/notification fixtures use their own states, not connector capabilities. */
final readonly class FixtureResult
{
    public bool $fake;

    public function __construct(public string $status, public string $capability, public ?string $reasonCode, public string $message)
    {
        $this->fake = true;
    }
}
