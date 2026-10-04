<?php

namespace App\Infrastructure\Security;

use DomainException;

class OutboundTargetValidator
{
    public function __construct(private readonly HostResolver $resolver) {}

    public function validate(string $url): string
    {
        $this->addresses($url);

        return $url;
    }

    /** Validate immediately before connecting; the transport must pin one of these IPs. */
    public function addresses(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new DomainException('Outbound target must have a scheme and host.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new DomainException('Outbound target scheme is not allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw new DomainException('Outbound target contains credentials or ambiguous characters.');
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
            if (! $this->isPublicAddress($address)) {
                throw new DomainException('Outbound target resolves to a blocked address.');
            }
        }

        return $addresses;
    }

    public function isPublicAddress(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $blocked = str_contains($address, ':')
            ? ['::/3', '4000::/2', '8000::/1', '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20']
            : ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3'];
        foreach ($blocked as $cidr) {
            [$network, $bits] = explode('/', $cidr);
            $packed = inet_pton($address);
            $base = inet_pton($network);
            $bytes = intdiv((int) $bits, 8);
            $remainder = (int) $bits % 8;
            if (substr($packed, 0, $bytes) === substr($base, 0, $bytes)
                && ($remainder === 0 || ((ord($packed[$bytes]) ^ ord($base[$bytes])) & (255 << (8 - $remainder))) === 0)) {
                return false;
            }
        }

        return true;
    }
}
