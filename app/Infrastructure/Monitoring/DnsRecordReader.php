<?php

namespace App\Infrastructure\Monitoring;

interface DnsRecordReader
{
    public function records(string $host, string $type): array;
}
