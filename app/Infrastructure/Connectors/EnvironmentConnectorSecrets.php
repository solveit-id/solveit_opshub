<?php

namespace App\Infrastructure\Connectors;

use Illuminate\Support\Env;
use LogicException;

/** Development references only; production requires a secrets-manager implementation. */
class EnvironmentConnectorSecrets implements ConnectorSecretResolver
{
    public function resolve(string $reference): string
    {
        if (! app()->runningInConsole() || app()->environment('production')
            || ! preg_match('/^env:(OPSHUB_CONNECTOR_[A-Z0-9_]{3,100})$/D', $reference, $m)) {
            throw new LogicException('Connector secret resolution is unavailable.');
        }
        $secret = Env::get($m[1]);
        if (! is_string($secret) || $secret === '') {
            throw new LogicException('Connector secret reference is not provisioned.');
        }

        return $secret;
    }
}
