<?php

namespace App\Infrastructure\Connectors;

readonly class ConnectorResult
{
    public function __construct(
        public string $status,
        public string $capability,
        public ?string $reasonCode,
        public string $message,
        public bool $fake = true,
    ) {}
}
