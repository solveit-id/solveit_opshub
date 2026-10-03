<?php

namespace App\Infrastructure\Security;

use DomainException;

class OutboundTargetValidator
{
    public function __construct(private readonly HostResolver $resolver) {}

    public function validate(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new DomainException('Outbound target must have a scheme and host.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new DomainException('Outbound target scheme is not allowed.');
        }

        if (! in_array($port, config('opshub.outbound_allowed_ports'), true)) {
            throw new DomainException('Outbound target port is not allowed.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new DomainException('Outbound localhost target is not allowed.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new DomainException('Outbound target could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new DomainException('Outbound target resolves to a blocked address.');
            }
        }

        return $url;
    }
}
