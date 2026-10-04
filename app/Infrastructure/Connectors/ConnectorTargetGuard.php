<?php

namespace App\Infrastructure\Connectors;

use App\Infrastructure\Security\OutboundTargetValidator;
use DomainException;

class ConnectorTargetGuard
{
    public function __construct(private readonly OutboundTargetValidator $publicTargets) {}

    public function addresses(ConnectorConfig $config): array
    {
        $parts = parse_url($config->endpoint);
        $port = $parts['port'] ?? ($config->kind === 'cpanel' ? 443 : 22);
        if (! in_array($port, $config->kind === 'cpanel' ? [443, 2083] : [22], true)) {
            throw new DomainException('Connector port not approved.');
        }

        // Dedicated protocols/ports do not expand the global public-probe port allowlist.
        return $this->publicTargets->addresses('https://'.$parts['host']);
    }
}
