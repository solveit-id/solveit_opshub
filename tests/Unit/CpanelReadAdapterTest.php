<?php

namespace Tests\Unit;

use App\Infrastructure\Connectors\BackupRequest;
use App\Infrastructure\Connectors\Capability;
use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Infrastructure\Connectors\ConnectorSecretResolver;
use App\Infrastructure\Connectors\ConnectorTargetGuard;
use App\Infrastructure\Connectors\Cpanel\CpanelAdapter;
use App\Infrastructure\Connectors\Cpanel\CpanelRead;
use App\Infrastructure\Connectors\Cpanel\CpanelResponse;
use App\Infrastructure\Connectors\Cpanel\NativeCpanelTransport;
use App\Infrastructure\Security\HostResolver;
use App\Infrastructure\Security\OutboundTargetValidator;
use App\Infrastructure\Testing\ScriptedCpanelTransport;
use DomainException;
use Illuminate\Support\Str;
use Tests\TestCase;

class CpanelReadAdapterTest extends TestCase
{
    private function config(string $endpoint = 'https://panel.example:2083'): ConnectorConfig
    {
        return new ConnectorConfig(1, 1, 'cpanel', $endpoint, 'demo', 'env:OPSHUB_CONNECTOR_TEST_TOKEN');
    }

    public function test_discovery_reads_quota_and_never_tests_remote_backup_with_a_write(): void
    {
        $transport = new ScriptedCpanelTransport([new CpanelResponse(200, ['result' => ['status' => 1, 'data' => ['megabyte_limit' => '100.00', 'megabytes_used' => '5.46']]])]);
        $adapter = new CpanelAdapter($transport);
        $results = $adapter->discoverCapabilities($this->config());
        $this->assertSame(104857600, $results[0]->evidence['quota_bytes']);
        $this->assertSame(5725225, $results[0]->evidence['used_bytes']);
        $this->assertTrue($results[0]->fake);
        $this->assertSame(['Quota/get_quota_info'], array_column($transport->calls, 0));
        foreach (array_slice($results, 1) as $result) {
            $this->assertFalse($result->successful());
        }
        $run = new BackupRequest('opshub-'.Str::uuid(), Capability::FullBackupTrigger);
        $this->assertFalse($adapter->requestBackup($this->config(), $run)->successful());
        $this->assertFalse($adapter->reconcile($this->config(), $run)->successful());
        $this->assertCount(1, $transport->calls);
    }

    public function test_null_zero_unlimited_and_invalid_quotas_remain_unknown(): void
    {
        foreach ([null, 0, '0.00', 'unlimited', -1, '1e99'] as $quota) {
            $adapter = new CpanelAdapter(new ScriptedCpanelTransport([new CpanelResponse(200, ['result' => ['status' => 1, 'data' => ['megabyte_limit' => $quota, 'megabytes_used' => null]]])]));
            $r = $adapter->readObservation($this->config(), Capability::AccountDiskRead);
            $this->assertNull($r->evidence['quota_bytes']);
            $this->assertNull($r->evidence['used_bytes']);
            $this->assertArrayNotHasKey('percentage', $r->evidence);
        }
    }

    public function test_provider_failures_are_typed_bounded_and_do_not_reflect_secret_errors(): void
    {
        $cases = [[401, null, 'AUTH_FAILED'], [403, null, 'PERMISSION_DENIED'], [429, null, 'RATE_LIMITED'],
            [302, null, 'TARGET_BLOCKED'], [200, null, 'PROVIDER_FEATURE_DISABLED'], [0, ConnectorReason::TlsInvalid, 'TLS_INVALID'],
            [0, ConnectorReason::Timeout, 'NETWORK_TIMEOUT'], [200, ConnectorReason::ResponseInvalid, 'RESPONSE_INVALID']];
        foreach ($cases as [$status, $error, $code]) {
            $transport = new ScriptedCpanelTransport([new CpanelResponse($status, ['result' => ['status' => 0, 'errors' => ['cpanel demo:private-token'], 'data' => []]], $error)]);
            $r = (new CpanelAdapter($transport))->validateConfig($this->config());
            $this->assertSame($code, $r->reasonCode);
            $this->assertFalse($r->successful());
            $this->assertStringNotContainsString('private-token', json_encode($r));
            $this->assertCount(1, $transport->calls);
        }
    }

    public function test_native_disabled_gate_does_not_resolve_a_secret_or_contact_a_target(): void
    {
        $resolver = new class implements HostResolver
        {
            public int $calls = 0;

            public function resolve(string $host): array
            {
                $this->calls++;

                return ['8.8.8.8'];
            }
        };
        $secrets = new class implements ConnectorSecretResolver
        {
            public int $calls = 0;

            public function resolve(string $reference): string
            {
                $this->calls++;

                return 'fictitioussecretvalue';
            }
        };
        config(['opshub.live_connectors_enabled' => false]);
        $native = new NativeCpanelTransport(new ConnectorTargetGuard(new OutboundTargetValidator($resolver)), $secrets);
        $this->assertSame(ConnectorReason::NotConfigured, $native->read($this->config(), CpanelRead::Features)->error);
        $this->assertSame(0, $secrets->calls);
        $this->assertSame(0, $resolver->calls);
    }

    public function test_connector_ssrf_guard_rejects_private_rebinding_and_unapproved_ports(): void
    {
        $guard = new ConnectorTargetGuard(new OutboundTargetValidator(new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'panel.example' ? ['8.8.8.8'] : ['8.8.8.8', '169.254.169.254'];
            }
        }));
        $this->assertSame(['8.8.8.8'], $guard->addresses($this->config()));
        $this->assertNotContains(2083, config('opshub.outbound_allowed_ports'));
        foreach (['https://private.example:2083', 'https://127.0.0.1:2083', 'https://[::1]:2083', 'https://panel.example:2087'] as $url) {
            try {
                $guard->addresses($this->config($url));
                $this->fail('Blocked connector target was accepted.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
