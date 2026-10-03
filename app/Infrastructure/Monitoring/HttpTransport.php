<?php

namespace App\Infrastructure\Monitoring;

interface HttpTransport
{
    /** Body and Location are transient and must never be persisted. */
    public function get(string $url, string $address, float $timeout, int $bodyLimit): array;
}
