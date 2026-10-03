<?php

namespace App\Infrastructure\Monitoring;

class NativeDnsRecordReader implements DnsRecordReader
{
    public function records(string $host, string $type): array
    {
        $records = @dns_get_record($host, constant('DNS_'.$type));
        if ($records === false) {
            throw new ProbeTransportException('DNS_UNAVAILABLE');
        }

        return array_values(array_map(fn ($record) => match ($type) {
            'A' => $record['ip'], 'AAAA' => $record['ipv6'], default => $record['target'],
        }, array_filter($records, fn ($record) => ($record['type'] ?? '') === $type)));
    }
}
