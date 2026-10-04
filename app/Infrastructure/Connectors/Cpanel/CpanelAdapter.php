<?php

namespace App\Infrastructure\Connectors\Cpanel;

use App\Infrastructure\Connectors\BackupRequest;
use App\Infrastructure\Connectors\Capability;
use App\Infrastructure\Connectors\ConnectorAdapter;
use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Infrastructure\Connectors\ConnectorResult;

class CpanelAdapter implements ConnectorAdapter
{
    public function __construct(private readonly CpanelTransport $transport) {}

    public function validateConfig(ConnectorConfig $config): ConnectorResult
    {
        return $this->response($config, CpanelRead::Features, Capability::Connection);
    }

    public function discoverCapabilities(ConnectorConfig $config): array
    {
        // Feature names are hints, never evidence that a backup write/retrieval path was tested.
        $quota = $this->readObservation($config, Capability::AccountDiskRead);

        return [$quota,
            new ConnectorResult('unknown', Capability::FullBackupTrigger->value, 'RECONCILE_REQUIRED', fake: $quota->fake),
            new ConnectorResult('unknown', Capability::BackupArtifactPull->value, 'NOT_CONFIGURED', fake: $quota->fake),
            new ConnectorResult('unsupported', Capability::FullAccountRestore->value, 'UNSUPPORTED_CAPABILITY', fake: $quota->fake),
            new ConnectorResult('unsupported', Capability::DatabaseBackup->value, 'UNSUPPORTED_CAPABILITY', fake: $quota->fake)];
    }

    public function readObservation(ConnectorConfig $config, Capability $capability): ConnectorResult
    {
        return $capability === Capability::AccountDiskRead ? $this->response($config, CpanelRead::Quota, $capability)
            : new ConnectorResult('unsupported', $capability->value, 'UNSUPPORTED_CAPABILITY');
    }

    public function requestBackup(ConnectorConfig $config, BackupRequest $request): ConnectorResult
    {
        return new ConnectorResult('unknown', $request->capability->value, 'NOT_CONFIGURED');
    }

    public function reconcile(ConnectorConfig $config, BackupRequest $request): ConnectorResult
    {
        return new ConnectorResult('unknown', $request->capability->value, 'RECONCILE_REQUIRED');
    }

    private function response(ConnectorConfig $config, CpanelRead $operation, Capability $capability): ConnectorResult
    {
        if ($config->kind !== 'cpanel') {
            return new ConnectorResult('not_configured', $capability->value, 'INVALID_CONFIGURATION');
        }
        $response = $this->transport->read($config, $operation);
        $reason = match (true) {
            $response->httpStatus === 401 => ConnectorReason::AuthFailed,
            $response->httpStatus === 403 => ConnectorReason::PermissionDenied,
            $response->httpStatus === 429 => ConnectorReason::RateLimited,
            $response->error !== null => $response->error,
            $response->httpStatus >= 300 && $response->httpStatus < 400 => ConnectorReason::TargetBlocked,
            $response->httpStatus !== 200 => ConnectorReason::Network,
            ! isset($response->body['result']['status']) => ConnectorReason::ResponseInvalid,
            $response->body['result']['status'] !== 1 => ConnectorReason::FeatureDisabled,
            ! is_array($response->body['result']['data'] ?? null) => ConnectorReason::ResponseInvalid,
            default => null,
        };
        if ($reason !== null) {
            return new ConnectorResult(match ($reason) {
                ConnectorReason::AuthFailed, ConnectorReason::PermissionDenied => 'permission_denied',
                ConnectorReason::FeatureDisabled => 'unsupported',
                ConnectorReason::NotConfigured => 'not_configured',
                default => 'fail',
            }, $capability->value, $reason->value, fake: $response->fake, evidence: ['http_status' => $response->httpStatus],
                retryable: in_array($reason, [ConnectorReason::Timeout, ConnectorReason::Network, ConnectorReason::RateLimited], true));
        }
        $evidence = ['http_status' => 200];
        if ($capability === Capability::AccountDiskRead) {
            $data = $response->body['result']['data'];
            $evidence += ['quota_bytes' => $this->bytes($data['megabyte_limit'] ?? null, true), 'used_bytes' => $this->bytes($data['megabytes_used'] ?? null)];
        }

        return new ConnectorResult('supported', $capability->value, null, fake: $response->fake, evidence: $evidence);
    }

    private function bytes(mixed $value, bool $limit = false): ?int
    {
        // 0 quota means unlimited/disabled, not a percentage denominator or zero free space.
        if (! is_numeric($value) || (float) $value < 0 || ($limit && (float) $value === 0.0)
            || ! is_finite((float) $value) || (float) $value > PHP_INT_MAX / 1048576) {
            return null;
        }

        return (int) round((float) $value * 1048576);
    }
}
