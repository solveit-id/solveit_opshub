<?php

namespace App\Application\Connectors;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\Registry\ManagementAuthorizationService;
use App\Infrastructure\Connectors\ConnectorAdapter;
use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorResult;
use App\Infrastructure\Connectors\Cpanel\CpanelAdapter;
use App\Infrastructure\Connectors\Sftp\SftpAdapter;
use App\Jobs\TestConnector;
use App\Models\Connector;
use App\Models\ConnectorTestRun;
use App\Models\HostingAccount;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class ConnectorTests
{
    public function enqueue(Organization $org, User $actor, Connector $connector, int $version, string $key, ?string $candidate = null): ConnectorTestRun
    {
        return DB::transaction(function () use ($org, $actor, $connector, $version, $key, $candidate): ConnectorTestRun {
            $org = $org->fresh();
            $connector = Connector::whereKey($connector->id)->lockForUpdate()->firstOrFail();
            abort_unless($connector->organization_id === $org->id, 404);
            app(ConnectorAccess::class)->requireAccount($org, $actor->fresh(), HostingAccount::findOrFail($connector->hosting_account_id), true);
            abort_unless(preg_match('/^[a-zA-Z0-9_.-]{8,100}$/D', $key), 422);
            $digest = hash('sha256', json_encode([$version, $candidate], JSON_THROW_ON_ERROR));
            $existing = ConnectorTestRun::where('connector_id', $connector->id)->where('actor_user_id', $actor->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_unless($existing->request_digest === $digest, 409);
                // Explicit replay may recover an expired read-only lease. Never auto retry auth failure.
                if ($existing->state === 'running' && ! $existing->leased_until?->isFuture() && $existing->attempts < 3) {
                    $existing->update(['state' => 'queued', 'lease_owner' => null, 'leased_until' => null]);
                    TestConnector::dispatch($existing->id)->onConnection('database')->onQueue(config('opshub.queue.routine'));
                } elseif ($existing->state === 'running' && ! $existing->leased_until?->isFuture()) {
                    $existing->update(['state' => 'failed', 'reason_code' => 'NETWORK_TIMEOUT', 'completed_at' => now('UTC'), 'leased_until' => null, 'lease_owner' => null]);
                }

                return $existing;
            }
            abort_unless($connector->version === $version && $connector->state !== 'disabled', 409);
            abort_if(ConnectorTestRun::where('connector_id', $connector->id)->whereIn('state', ['queued', 'running'])->exists(), 409);
            abort_if(ConnectorTestRun::where('connector_id', $connector->id)->where('created_at', '>', now('UTC')->subSeconds(30))->exists(), 429);
            if ($candidate !== null) {
                $old = $connector->snapshot();
                new ConnectorConfig($org->id, $connector->hosting_account_id, $connector->kind, $old->endpoint, $old->accountIdentifier, $candidate, $old->roots, $old->hostFingerprint);
            }
            $run = ConnectorTestRun::create(['organization_id' => $org->id, 'connector_id' => $connector->id, 'actor_user_id' => $actor->id,
                'configuration_version' => $version, 'idempotency_key' => $key, 'request_digest' => $digest, 'candidate_reference' => $candidate]);
            TestConnector::dispatch($run->id)->onConnection('database')->onQueue(config('opshub.queue.routine'));
            app(AuditWriter::class)->write($org, $candidate ? 'connector.rotation.requested' : 'connector.test.requested', 'connector', $connector->id, 'queued', $actor,
                after: ['run_id' => $run->id, 'version' => $version]);

            return $run;
        });
    }

    public function execute(int $id, ?ConnectorAdapter $adapter = null): void
    {
        if ($adapter !== null && ! app()->environment('testing')) {
            throw new LogicException('Explicit adapter injection is test-only.');
        }
        $claim = DB::transaction(function () use ($id): ?array {
            $reference = ConnectorTestRun::findOrFail($id);
            $connector = Connector::whereKey($reference->connector_id)->lockForUpdate()->firstOrFail();
            $run = ConnectorTestRun::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($run->state, ['queued', 'running'], true) || ($run->state === 'running' && $run->leased_until?->isFuture())) {
                return null;
            }
            if (! $this->authorized($connector, $run) || $connector->version !== $run->configuration_version || $run->attempts >= 3) {
                $run->update(['state' => 'cancelled', 'reason_code' => 'AUTHORIZATION_EXPIRED', 'completed_at' => now('UTC')]);

                return null;
            }
            $config = $connector->snapshot();
            if ($run->candidate_reference) {
                $config = new ConnectorConfig($connector->organization_id, $connector->hosting_account_id, $connector->kind, $config->endpoint,
                    $config->accountIdentifier, $run->candidate_reference, $config->roots, $config->hostFingerprint, $config->version);
            }
            $token = (string) Str::uuid();
            $run->update(['state' => 'running', 'lease_owner' => $token, 'leased_until' => now('UTC')->addSeconds(60), 'attempts' => $run->attempts + 1]);

            return [$connector, $run, $config, $token];
        });
        if (! $claim) {
            return;
        }
        [$connector, $run, $config, $token] = $claim;
        try {
            $adapter ??= $connector->kind === 'cpanel' ? app(CpanelAdapter::class) : app(SftpAdapter::class);
            $connection = $adapter?->validateConfig($config) ?? new ConnectorResult('not_configured', 'connection', 'NOT_CONFIGURED');
            $results = $connection->successful() ? $adapter->discoverCapabilities($config) : [];
            if ($run->candidate_reference) {
                foreach ($results as $result) {
                    if (in_array($result->capability, ['account_disk_read', 'sftp_read'], true)
                        && in_array($result->status, ['permission_denied', 'fail', 'unknown', 'not_configured'], true)) {
                        $connection = new ConnectorResult($result->status, 'connection', $result->reasonCode, fake: $result->fake);
                    }
                }
            }
        } catch (Throwable) {
            $connection = new ConnectorResult('unknown', 'connection', 'NETWORK_ERROR');
            $results = [];
        }
        DB::transaction(function () use ($connector, $run, $token, $connection, $results): void {
            $connector = Connector::whereKey($connector->id)->lockForUpdate()->firstOrFail();
            $run = ConnectorTestRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->lease_owner !== $token || $run->state !== 'running' || ! $run->leased_until?->isFuture()) {
                return;
            }
            if (! $this->authorized($connector, $run) || $connector->version !== $run->configuration_version) {
                $run->update(['state' => 'cancelled', 'reason_code' => 'AUTHORIZATION_EXPIRED', 'completed_at' => now('UTC')]);

                return;
            }
            if ($run->candidate_reference && ! $connection->successful()) {
                // A failed candidate never replaces active credentials or poisons their history.
                $run->update(['state' => 'failed', 'reason_code' => $connection->reasonCode, 'fake' => $connection->fake, 'completed_at' => now('UTC'), 'leased_until' => null]);

                return;
            }
            if ($run->candidate_reference) {
                $configuration = $connector->configuration;
                $oldReference = $configuration['secret_reference'];
                $configuration['secret_reference'] = $run->candidate_reference;
                $connector->update(['configuration' => $configuration, 'version' => $connector->version + 1, 'validation_state' => 'live_unverified', 'writes_paused' => true]);
                $run->revoke_reference = $oldReference;
                app(AuditWriter::class)->write(Organization::findOrFail($connector->organization_id), 'connector.reference.rotated', 'connector', $connector->id, 'success',
                    User::findOrFail($run->actor_user_id), after: ['version' => $connector->version, 'run_id' => $run->id, 'provider_revocation' => 'operator_required']);
            }
            app(CapabilityRecorder::class)->record($connector, $connector->version, 'validate', $connection);
            foreach ($results as $result) {
                app(CapabilityRecorder::class)->record($connector, $connector->version, 'discover', $result);
            }
            $problem = $connection->successful() ? null : $connection;
            foreach ($results as $result) {
                if (in_array($result->capability, ['account_disk_read', 'sftp_read'], true)
                    && in_array($result->status, ['permission_denied', 'fail', 'unknown', 'not_configured'], true)) {
                    $problem ??= $result;
                }
            }
            $run->fill(['state' => $problem ? 'failed' : 'completed', 'reason_code' => $problem?->reasonCode,
                'fake' => $connection->fake, 'completed_at' => now('UTC'), 'leased_until' => null])->save();
        });
    }

    private function authorized(Connector $connector, ConnectorTestRun $run): bool
    {
        if ($connector->state === 'disabled' || $run->organization_id !== $connector->organization_id) {
            return false;
        }
        try {
            $org = Organization::findOrFail($connector->organization_id);
            app(ConnectorAccess::class)->requireAccount($org, User::findOrFail($run->actor_user_id), HostingAccount::findOrFail($connector->hosting_account_id), true);

            return app(ManagementAuthorizationService::class)->allows($org, 'hosting_account', $connector->hosting_account_id, 'observe');
        } catch (Throwable) {
            return false;
        }
    }
}
