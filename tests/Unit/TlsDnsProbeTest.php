<?php

namespace Tests\Unit;

use App\Infrastructure\Monitoring\DnsProbe;
use App\Infrastructure\Monitoring\DnsRecordReader;
use App\Infrastructure\Monitoring\ProbeTransportException;
use App\Infrastructure\Monitoring\TlsProbe;
use App\Infrastructure\Monitoring\TlsTransport;
use App\Infrastructure\Security\HostResolver;
use App\Infrastructure\Security\OutboundTargetValidator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class TlsDnsProbeTest extends TestCase
{
    private function validator(): OutboundTargetValidator
    {
        return new OutboundTargetValidator(new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        });
    }

    public function test_tls_thresholds_validity_and_peer(): void
    {
        $now = CarbonImmutable::parse('2026-10-04T00:00:00Z');
        foreach ([31 => 'pass', 30 => 'warn', 7 => 'fail', 0 => 'fail'] as $days => $expected) {
            $transport = new class($now->addDays($days)->timestamp) implements TlsTransport
            {
                public function __construct(private int $expiry) {}

                public function inspect(string $host, int $port, string $address): array
                {
                    return ['peer' => $address, 'expires_at' => $this->expiry, 'hostname_valid' => true, 'chain_valid' => true];
                }
            };
            $probe = new TlsProbe($this->validator(), $transport);
            $this->assertSame($expected, $probe->check('https://public.example', now: $now)->outcome);
            $this->assertSame('unsupported', $probe->check('http://public.example')->outcome);
            $this->assertSame('unknown', $probe->check('https://127.0.0.1')->outcome);
        }
        $invalid = new class implements TlsTransport
        {
            public function inspect(string $host, int $port, string $address): array
            {
                throw new ProbeTransportException('TLS_INVALID');
            }
        };
        $this->assertSame('fail', (new TlsProbe($this->validator(), $invalid))->check('https://public.example')->outcome);
    }

    public function test_dns_allowed_records_match_unavailable_and_private(): void
    {
        foreach ([['8.8.8.8'], [], ['127.0.0.1'], 'unavailable'] as $values) {
            $reader = new class($values) implements DnsRecordReader
            {
                public function __construct(private array|string $values) {}

                public function records(string $host, string $type): array
                {
                    if (is_string($this->values)) {
                        throw new ProbeTransportException('DNS_UNAVAILABLE');
                    }

                    return $this->values;
                }
            };
            $probe = new DnsProbe($this->validator(), $reader);
            $result = $probe->check('public.example');
            $expected = match ($values) {
                ['8.8.8.8'] => 'pass', [] => 'fail', default => 'unknown'
            };
            $this->assertSame($expected, $result->outcome);
            $this->assertSame('unsupported', $probe->check('public.example', ['record_type' => 'TXT'])->outcome);
            $this->assertSame('TARGET_BLOCKED', $probe->check('localhost')->reason);
            if ($expected === 'pass') {
                $this->assertSame('DNS_MISMATCH', $probe->check('public.example', ['expected_values' => ['1.1.1.1']])->reason);
                $this->assertSame('pass', $probe->check('public.example', ['expected_values' => ['8.8.8.8']])->outcome);
            }
        }
    }
}
