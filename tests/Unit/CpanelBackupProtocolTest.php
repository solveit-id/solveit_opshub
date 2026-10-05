<?php

namespace Tests\Unit;

use App\Infrastructure\Backup\BackupWritePermit;
use App\Infrastructure\Backup\CpanelSourceArtifacts;
use App\Infrastructure\Backup\NativeCpanelBackupSource;
use App\Infrastructure\Connectors\Cpanel\CpanelBackupTransport;
use App\Infrastructure\Connectors\Cpanel\CpanelResponse;
use App\Infrastructure\Connectors\Cpanel\NativeCpanelTransport;
use App\Models\BackupRun;
use App\Models\Connector;
use Tests\TestCase;

class CpanelBackupProtocolTest extends TestCase
{
    private function connector(): Connector
    {
        return new Connector(['organization_id' => 1, 'hosting_account_id' => 1, 'kind' => 'cpanel', 'version' => 1,
            'configuration' => ['endpoint' => 'https://panel.example:2083', 'account_identifier' => 'demo', 'secret_reference' => 'env:OPSHUB_CONNECTOR_SOURCE_TEST']]);
    }

    public function test_synthetic_official_response_parser_retains_only_pid_and_never_returns_backup_success(): void
    {
        // Parser fixtures only: these response objects are never persisted as provider evidence.
        $cases = [
            [new CpanelResponse(200, ['result' => ['status' => 1, 'data' => ['pid' => '123'], 'messages' => ['private-value']]]), 'accepted', '123', null],
            [new CpanelResponse(200, ['result' => ['status' => 1, 'data' => ['pid' => 'raw-secret-value']]]), 'uncertain', null, 'RESPONSE_INVALID'],
            [new CpanelResponse(200, ['result' => 'malformed-provider-value']), 'uncertain', null, 'RESPONSE_INVALID'],
            [new CpanelResponse(200, ['result' => ['status' => 0, 'errors' => ['private-error']]]), 'rejected', null, 'PROVIDER_FEATURE_DISABLED'],
            [new CpanelResponse(401), 'rejected', null, 'AUTH_FAILED'],
            [new CpanelResponse(403), 'rejected', null, 'PERMISSION_DENIED'],
            [new CpanelResponse(429), 'uncertain', null, 'RATE_LIMITED'],
            [new CpanelResponse(200, ['result' => ['status' => 1, 'data' => ['pid' => '123']]], fake: true), 'rejected', null, 'NOT_CONFIGURED'],
        ];
        foreach ($cases as [$response, $state, $pid, $reason]) {
            $transport = \Mockery::mock(CpanelBackupTransport::class);
            $transport->shouldReceive('request')->once()->andReturn($response);
            $artifacts = \Mockery::mock(CpanelSourceArtifacts::class);
            $artifacts->shouldReceive('configured')->once()->andReturn(true);
            $source = new NativeCpanelBackupSource($transport, $artifacts);
            $result = $source->request(new BackupRun, $this->connector(), BackupWritePermit::issue(1, 'fictitious-unused-permit'));
            $this->assertSame($state, $result->state);
            $this->assertSame($pid, $result->providerReference);
            $this->assertSame($reason, $result->reason?->value);
            $this->assertStringNotContainsString('private-value', json_encode($result));
            $this->assertStringNotContainsString('raw-secret-value', json_encode($result));
        }
    }

    public function test_native_false_gate_and_unconfigured_completion_path_do_not_contact_provider_or_resolve_permit(): void
    {
        config(['opshub.live_connectors_enabled' => false]);
        $response = app(NativeCpanelTransport::class)->request($this->connector()->snapshot(), BackupWritePermit::issue(0, 'fictitious-unused-permit'));
        $this->assertSame('NOT_CONFIGURED', $response->error->value);
        $transport = \Mockery::mock(CpanelBackupTransport::class);
        $transport->shouldNotReceive('request');
        $artifacts = \Mockery::mock(CpanelSourceArtifacts::class);
        $artifacts->shouldReceive('configured')->once()->andReturn(false);
        $result = (new NativeCpanelBackupSource($transport, $artifacts))->request(new BackupRun, $this->connector(), BackupWritePermit::issue(0, 'fictitious-unused-permit'));
        $this->assertSame('rejected', $result->state);
        $this->assertSame('NOT_CONFIGURED', $result->reason->value);
    }
}
