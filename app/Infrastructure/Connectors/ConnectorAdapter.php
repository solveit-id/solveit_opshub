<?php

namespace App\Infrastructure\Connectors;

interface ConnectorAdapter
{
    public function validateConfig(ConnectorConfig $config): ConnectorResult;

    /** Read-only: may never call a backup trigger or otherwise change the provider. @return list<ConnectorResult> */
    public function discoverCapabilities(ConnectorConfig $config): array;

    public function readObservation(ConnectorConfig $config, Capability $capability): ConnectorResult;

    /** Application preflight/authorization/locks must already have passed. Acceptance is not backup success. */
    public function requestBackup(ConnectorConfig $config, BackupRequest $request): ConnectorResult;

    public function reconcile(ConnectorConfig $config, BackupRequest $request): ConnectorResult;
}
