<?php

namespace App\Infrastructure\Monitoring;

use App\Infrastructure\Security\OutboundTargetValidator;
use DomainException;

class DnsProbe
{
    public function __construct(private readonly OutboundTargetValidator $validator, private readonly DnsRecordReader $reader) {}

    public function check(string $host, array $options = []): ProbeResult
    {
        $type = $options['record_type'] ?? 'A';
        if (! in_array($type, ['A', 'AAAA', 'CNAME', 'MX', 'NS'], true)) {
            return new ProbeResult('unsupported', 'DNS_RECORD_UNSUPPORTED');
        }
        if (! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/i', $host)) {
            return new ProbeResult('unknown', 'TARGET_BLOCKED');
        }
        try {
            $values = $this->reader->records($host, $type);
            $values = array_values(array_unique(array_map(fn ($value) => strtolower(rtrim($value, '.')), $values)));
            sort($values);
            if (count($values) > 100) {
                throw new ProbeTransportException('DNS_RECORD_LIMIT');
            }
            foreach ($values as $value) {
                if (in_array($type, ['A', 'AAAA'], true)) {
                    if (! $this->validator->isPublicAddress($value)) {
                        throw new DomainException('Blocked DNS address.');
                    }
                } elseif (! preg_match('/^[a-z0-9.-]{1,253}$/', $value)) {
                    throw new DomainException('Invalid DNS name.');
                }
            }
            $expected = isset($options['expected_values']) ? array_map(fn ($value) => strtolower(rtrim($value, '.')), $options['expected_values']) : null;
            if ($expected !== null) {
                sort($expected);
            }
            $match = $expected === null ? null : $values === $expected;
            $passed = $values !== [] && $match !== false;

            return new ProbeResult($passed ? 'pass' : 'fail', $passed ? 'DNS_OK' : ($values === [] ? 'DNS_EMPTY' : 'DNS_MISMATCH'), [
                'record_type' => $type, 'values' => $values, 'expected_check' => $match === null ? 'not_configured' : ($match ? 'match' : 'mismatch'),
            ]);
        } catch (DomainException) {
            return new ProbeResult('unknown', 'TARGET_BLOCKED');
        } catch (ProbeTransportException $exception) {
            return new ProbeResult('unknown', $exception->getMessage());
        }
    }
}
