<?php

namespace App\Infrastructure\Monitoring;

use App\Infrastructure\Security\OutboundTargetValidator;
use Carbon\CarbonImmutable;
use DomainException;

class TlsProbe
{
    public function __construct(private readonly OutboundTargetValidator $validator, private readonly TlsTransport $transport) {}

    public function check(string $url, array $options = [], ?CarbonImmutable $now = null): ProbeResult
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            return new ProbeResult('unsupported', 'TLS_NOT_APPLICABLE');
        }
        try {
            $addresses = $this->validator->addresses($url);
            $certificate = $this->transport->inspect(trim($parts['host'], '[]'), $parts['port'] ?? 443, $addresses[0]);
            if (! in_array($certificate['peer'], $addresses, true) || ! $this->validator->isPublicAddress($certificate['peer'])) {
                throw new DomainException('Peer mismatch.');
            }
            $now ??= CarbonImmutable::now('UTC');
            $days = ($certificate['expires_at'] - $now->timestamp) / 86400;
            $valid = $certificate['hostname_valid'] && $certificate['chain_valid'] && $days > 0;
            $outcome = ! $valid || $days <= ($options['critical_days'] ?? 7) ? 'fail' : ($days <= ($options['warning_days'] ?? 30) ? 'warning' : 'pass');

            return new ProbeResult($outcome, ! $valid ? 'TLS_INVALID' : ($outcome === 'pass' ? 'TLS_OK' : 'TLS_EXPIRING'), [
                'expires_at' => CarbonImmutable::createFromTimestampUTC($certificate['expires_at'])->toIso8601String(),
                'remaining_days' => (int) floor($days), 'hostname_valid' => (bool) $certificate['hostname_valid'], 'chain_valid' => (bool) $certificate['chain_valid'],
            ]);
        } catch (DomainException) {
            return new ProbeResult('unknown', 'TARGET_BLOCKED');
        } catch (ProbeTransportException $exception) {
            return new ProbeResult($exception->getMessage() === 'TLS_INVALID' ? 'fail' : 'unknown', $exception->getMessage());
        }
    }
}
