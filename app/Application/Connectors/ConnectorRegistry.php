<?php

namespace App\Application\Connectors;

use App\Application\ActivityEvidence\AuditWriter;
use App\Infrastructure\Connectors\ConnectorConfig;
use App\Models\Connector;
use App\Models\HostingAccount;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConnectorRegistry
{
    public function configure(Organization $org, User $actor, ConnectorConfig $config, ?int $expectedVersion = null): Connector
    {
        return DB::transaction(function () use ($org, $actor, $config, $expectedVersion): Connector {
            $org = $org->fresh();
            abort_unless($config->organizationId === $org->id, 404);
            $account = HostingAccount::forOrganization($org)->lockForUpdate()->findOrFail($config->hostingAccountId);
            app(ConnectorAccess::class)->requireAccount($org, $actor->fresh(), $account, true);
            $connector = Connector::forOrganization($org)->where('hosting_account_id', $account->id)->where('kind', $config->kind)->lockForUpdate()->first();
            abort_unless($connector ? $expectedVersion === $connector->version : $expectedVersion === null, 409);
            // Rotation is a distinct test-then-switch workflow, never an untested config edit.
            abort_if($connector && $connector->configuration['secret_reference'] !== $config->secretReference, 422, 'Use the tested reference rotation workflow.');
            $connector ??= new Connector(['organization_id' => $org->id, 'hosting_account_id' => $account->id, 'kind' => $config->kind, 'version' => 0]);
            $connector->fill(['configuration' => ['endpoint' => $config->endpoint, 'account_identifier' => $config->accountIdentifier,
                'secret_reference' => $config->secretReference, 'roots' => $config->roots, 'host_fingerprint' => $config->hostFingerprint],
                'version' => $connector->version + 1, 'state' => 'not_configured', 'validation_state' => 'live_unverified', 'writes_paused' => true, 'last_tested_at' => null])->save();
            app(AuditWriter::class)->write($org, 'connector.configured', 'connector', $connector->id, 'success', $actor,
                after: ['kind' => $config->kind, 'version' => $connector->version, 'writes_paused' => true]);

            return $connector;
        });
    }
}
