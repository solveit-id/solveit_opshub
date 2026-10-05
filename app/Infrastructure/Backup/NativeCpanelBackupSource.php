<?php

namespace App\Infrastructure\Backup;

use App\Infrastructure\Connectors\ConnectorReason;
use App\Infrastructure\Connectors\Cpanel\CpanelBackupTransport;
use App\Models\BackupRun;
use App\Models\Connector;

class NativeCpanelBackupSource implements CpanelBackupSource
{
    public function __construct(private CpanelBackupTransport $transport, private CpanelSourceArtifacts $artifacts) {}

    public function fake(): bool
    {
        return false;
    }

    public function configured(): bool
    {
        return $this->artifacts->configured();
    }

    public function request(BackupRun $run, Connector $connector, BackupWritePermit $permit): SourceAcceptance
    {
        if (! $this->configured()) {
            return new SourceAcceptance('rejected', null, ConnectorReason::NotConfigured);
        }
        $response = $this->transport->request($connector->snapshot(), $permit);
        if ($response->fake) {
            return new SourceAcceptance('rejected', null, ConnectorReason::NotConfigured);
        }
        if (in_array($response->httpStatus, [401, 403], true)) {
            return new SourceAcceptance('rejected', null, $response->httpStatus === 401 ? ConnectorReason::AuthFailed : ConnectorReason::PermissionDenied);
        }
        if ($response->error) {
            return new SourceAcceptance('uncertain', null, $response->error);
        }
        if ($response->httpStatus === 429) {
            return new SourceAcceptance('uncertain', null, ConnectorReason::RateLimited);
        }
        $result = $response->body['result'] ?? null;
        if ($response->httpStatus === 200 && is_array($result) && ($result['status'] ?? null) === 0) {
            return new SourceAcceptance('rejected', null, ConnectorReason::FeatureDisabled);
        }
        $pid = is_array($result) && is_array($result['data'] ?? null) ? ($result['data']['pid'] ?? null) : null;
        if ($response->httpStatus !== 200 || ($result['status'] ?? null) !== 1 || ! is_string($pid) || ! preg_match('/^[1-9][0-9]{0,9}$/D', $pid)) {
            return new SourceAcceptance('uncertain', null, ConnectorReason::ResponseInvalid);
        }

        return new SourceAcceptance('accepted', $pid, null);
    }

    public function observe(BackupRun $run, Connector $connector, ?string $providerReference): SourceObservation
    {
        return $this->artifacts->observe($run, $connector, $providerReference);
    }

    public function stream(BackupRun $run, Connector $connector, SourceArtifact $artifact, \Closure $consume): array
    {
        return $this->artifacts->stream($run, $connector, $artifact, $consume);
    }
}
