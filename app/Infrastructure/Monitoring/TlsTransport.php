<?php

namespace App\Infrastructure\Monitoring;

interface TlsTransport
{
    public function inspect(string $host, int $port, string $address): array;
}
