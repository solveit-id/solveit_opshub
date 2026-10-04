<?php

namespace App\Infrastructure\Connectors\Fakes;

use App\Infrastructure\Connectors\BackupRequest;
use App\Infrastructure\Connectors\Capability;
use App\Infrastructure\Connectors\ConnectorAdapter;
use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorResult;
use LogicException;

/** Explicit test dependency. It is never bound as a production fallback. */
class ScriptedConnectorAdapter implements ConnectorAdapter
{
    public array $calls = [];

    public function __construct(private array $results = []) {}

    public function validateConfig(ConnectorConfig $config): ConnectorResult
    {
        return $this->result('validate', Capability::Connection);
    }

    public function discoverCapabilities(ConnectorConfig $config): array
    {
        return array_map(fn (Capability $capability) => $this->result('discover', $capability), array_filter(Capability::cases(), fn ($c) => $c !== Capability::Connection));
    }

    public function readObservation(ConnectorConfig $config, Capability $capability): ConnectorResult
    {
        return $this->result('read', $capability);
    }

    public function requestBackup(ConnectorConfig $config, BackupRequest $request): ConnectorResult
    {
        return $this->result('backup', $request->capability);
    }

    public function reconcile(ConnectorConfig $config, BackupRequest $request): ConnectorResult
    {
        return $this->result('reconcile', $request->capability);
    }

    private function result(string $operation, Capability $capability): ConnectorResult
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Scripted connectors are test-only.');
        }
        $this->calls[] = [$operation, $capability->value];
        $result = $this->results[$operation.'.'.$capability->value] ?? new ConnectorResult('unsupported', $capability->value, 'UNSUPPORTED_CAPABILITY', fake: true);
        if (! $result->fake || $result->capability !== $capability->value) {
            throw new LogicException('Invalid fake fixture provenance or capability.');
        }

        return $result;
    }
}
