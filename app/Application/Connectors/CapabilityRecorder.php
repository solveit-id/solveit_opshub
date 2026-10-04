<?php

namespace App\Application\Connectors;

use App\Application\TelegramNotifications\OutboxWriter;
use App\Infrastructure\Connectors\ConnectorResult;
use App\Models\Connector;
use App\Models\ConnectorAssessment;
use App\Models\HostingAccount;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use LogicException;

class CapabilityRecorder
{
    public function record(Connector $connector, int $version, string $operation, ConnectorResult $result): ConnectorAssessment
    {
        return DB::transaction(function () use ($connector, $version, $operation, $result): ConnectorAssessment {
            if (! in_array($operation, ['validate', 'discover', 'read', 'backup', 'reconcile'], true)
                || ($result->fake && ! app()->environment('testing'))
                || $result->observedAt->isFuture()) {
                throw new LogicException('Invalid connector assessment operation, provenance or time.');
            }
            $connector = Connector::whereKey($connector->id)->lockForUpdate()->firstOrFail();
            abort_unless($connector->version === $version, 409);
            $assessment = ConnectorAssessment::create(['organization_id' => $connector->organization_id, 'connector_id' => $connector->id,
                'configuration_version' => $version, 'operation' => $operation, 'capability' => $result->capability,
                'status' => $result->status, 'reason_code' => $result->reasonCode, 'retryable' => $result->retryable,
                'source' => $result->fake ? 'fake' : 'provider', 'observed_at' => $result->observedAt, 'evidence' => $result->evidence]);
            // Late observation may be kept as evidence, but may never reverse a newer connection state.
            if (in_array($operation, ['validate', 'discover', 'read'], true)
                && (! $connector->last_tested_at || $result->observedAt->greaterThanOrEqualTo($connector->last_tested_at))
                && $connector->state !== 'disabled') {
                $before = $connector->state;
                $connector->state = match (true) {
                    in_array($result->reasonCode, ['AUTH_FAILED', 'PERMISSION_DENIED'], true) => 'auth_failed',
                    $result->capability === 'connection' && $result->successful() => 'connected',
                    $result->status === 'not_configured' => 'not_configured',
                    in_array($result->status, ['fail', 'unknown'], true) && in_array($result->capability, ['connection', 'account_disk_read', 'sftp_read'], true) => 'degraded',
                    default => $connector->state,
                };
                $connector->last_tested_at = $result->observedAt;
                // Assessment alone never enables writes or claims a real sandbox validation.
                $connector->writes_paused = true;
                $connector->save();
                if (in_array($connector->state, ['auth_failed', 'degraded'], true) && $before !== $connector->state) {
                    app(OutboxWriter::class)->record(Organization::findOrFail($connector->organization_id), 'connector.failed', 'connector', $connector->id,
                        $assessment->id, ['connector_id' => $connector->id, 'reason_code' => $result->reasonCode, 'assessment_id' => $assessment->id,
                            'severity' => 'warning', 'route' => 'owner',
                            'impacted_project_ids' => HostingAccount::findOrFail($connector->hosting_account_id)->asset->usages()->pluck('project_id')->unique()->values()->all()]);
                }
            }

            return $assessment;
        });
    }

    public function current(Connector $connector): array
    {
        return ConnectorAssessment::forOrganization($connector->organization_id)->where('connector_id', $connector->id)
            ->where('configuration_version', $connector->version)->orderByDesc('observed_at')->orderByDesc('id')->get()
            ->unique('capability')->values()->toArray();
    }
}
