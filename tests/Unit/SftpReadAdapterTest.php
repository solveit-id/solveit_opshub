<?php

namespace Tests\Unit;

use App\Infrastructure\Connectors\Capability;
use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Infrastructure\Connectors\ConnectorSecretResolver;
use App\Infrastructure\Connectors\Sftp\NativeSftpSession;
use App\Infrastructure\Connectors\Sftp\RemotePathGuard;
use App\Infrastructure\Connectors\Sftp\SftpAdapter;
use App\Infrastructure\Testing\MemorySftpSessions;
use phpseclib4\Net\SFTP;
use Tests\TestCase;

class SftpReadAdapterTest extends TestCase
{
    private function config(): ConnectorConfig
    {
        return new ConnectorConfig(1, 1, 'sftp', 'sftp://sftp.example', 'demo', 'env:OPSHUB_CONNECTOR_SFTP_TEST', ['/srv/app'], 'SHA256:'.str_repeat('A', 43));
    }

    private function fake(): MemorySftpSessions
    {
        return new MemorySftpSessions(['/srv/app' => ['type' => 2], '/srv/app/a.txt' => ['type' => 1, 'size' => 3, 'mtime' => 10, 'content' => 'abc']]);
    }

    private function adapter(MemorySftpSessions $fake): SftpAdapter
    {
        return new SftpAdapter($fake, new RemotePathGuard);
    }

    private function refused(\Closure $operation, ConnectorReason $reason): void
    {
        try {
            $operation();
            $this->fail('Unsafe operation was accepted.');
        } catch (ConnectorFailure $error) {
            $this->assertSame($reason, $error->reason);
        }
    }

    public function test_host_key_mismatch_is_fake_failure_before_auth_and_session_always_closes(): void
    {
        $fake = $this->fake();
        $fake->pin = 'SHA256:'.str_repeat('B', 43);
        $result = $this->adapter($fake)->validateConfig($this->config());
        $this->assertSame('HOST_KEY_MISMATCH', $result->reasonCode);
        $this->assertSame('fail', $result->status);
        $this->assertTrue($result->fake);
        $this->assertSame(0, $fake->authentications);
        $this->assertSame(0, $fake->reads);
        $this->assertSame(1, $fake->closed);
    }

    public function test_paths_require_configured_canonical_root_and_reject_traversal_symlinks_and_listing_names(): void
    {
        $fake = $this->fake();
        $adapter = $this->adapter($fake);
        foreach (['../a.txt', '/etc/passwd', './a.txt', 'dir/../a.txt', 'a%2etxt', 'a\\txt', "a\x00txt", 'dir//a.txt'] as $path) {
            $this->refused(fn () => $adapter->stream($this->config(), '/srv/app', $path, fn () => null), ConnectorReason::PathBlocked);
        }
        $this->refused(fn () => $adapter->listing($this->config(), '/srv/other'), ConnectorReason::PathBlocked);
        $fake->entries['/srv/app/link'] = ['type' => 3, 'canonical' => '/etc/passwd'];
        $this->refused(fn () => $adapter->stream($this->config(), '/srv/app', 'link', fn () => null), ConnectorReason::PathBlocked);
        $fake->entries['/srv/app/dir'] = ['type' => 3, 'canonical' => '/etc'];
        $this->refused(fn () => $adapter->stream($this->config(), '/srv/app', 'dir/passwd', fn () => null), ConnectorReason::PathBlocked);
        $fake->entries['/srv/app']['listing'] = ['../escape' => ['type' => 1]];
        $this->refused(fn () => $adapter->listing($this->config(), '/srv/app'), ConnectorReason::PathBlocked);
        $fake->entries['/srv/app']['type'] = 3;
        $this->refused(fn () => $adapter->listing($this->config(), '/srv/app'), ConnectorReason::PathBlocked);
        $this->assertSame(0, $fake->reads);
    }

    public function test_stream_is_chunked_checksums_metadata_and_refuses_partial_or_changed_file(): void
    {
        $fake = $this->fake();
        $fake->entries['/srv/app/large'] = ['type' => 1, 'size' => 2 * 65536 + 1, 'mtime' => 20];
        $bytes = 0;
        $hash = hash_init('sha256');
        $manifest = $this->adapter($fake)->stream($this->config(), '/srv/app', 'large', function ($chunk) use (&$bytes, $hash): void {
            $this->assertLessThanOrEqual(65536, strlen($chunk));
            $bytes += strlen($chunk);
            hash_update($hash, $chunk);
        });
        $this->assertSame(131073, $bytes);
        $this->assertSame(hash_final($hash), $manifest['sha256']);
        $this->assertSame('large', $manifest['path']);
        $this->assertSame(20, $manifest['mtime']);
        $this->assertSame(3, $fake->reads);
        $fake->partial = true;
        $this->refused(fn () => $this->adapter($fake)->stream($this->config(), '/srv/app', 'a.txt', fn () => null), ConnectorReason::ArtifactIncomplete);
        $fake->partial = false;
        $fake->afterRead = function ($session, $path): void {
            $session->entries[$path]['mtime']++;
        };
        $delivered = 0;
        $this->refused(fn () => $this->adapter($fake)->stream($this->config(), '/srv/app', 'a.txt', function () use (&$delivered): void {
            $delivered++;
        }), ConnectorReason::ArtifactIncomplete);
        $this->assertSame(0, $delivered);
    }

    public function test_listing_and_byte_limits_and_typed_denial_keep_raw_data_private(): void
    {
        $fake = $this->fake();
        $adapter = $this->adapter($fake);
        $fake->entries['/srv/app/b.txt'] = ['type' => 1, 'size' => 2, 'mtime' => 10];
        $this->refused(fn () => $adapter->listing($this->config(), '/srv/app', '', 1), ConnectorReason::LimitExceeded);
        $this->refused(fn () => $adapter->stream($this->config(), '/srv/app', 'a.txt', fn () => null, 2), ConnectorReason::LimitExceeded);
        $fake->failure = ConnectorReason::PermissionDenied;
        $result = $adapter->validateConfig($this->config());
        $this->assertSame('permission_denied', $result->status);
        $this->assertSame('PERMISSION_DENIED', $result->reasonCode);
        $this->assertTrue($result->fake);
        $this->assertSame([], $result->evidence);
        $this->assertStringNotContainsString('/srv/app', json_encode($result));
        $this->assertStringNotContainsString('OPSHUB_CONNECTOR_SFTP_TEST', json_encode($result));
    }

    public function test_discovery_remains_read_only_database_gap_and_native_false_gate_are_explicit(): void
    {
        $fake = $this->fake();
        $results = $this->adapter($fake)->discoverCapabilities($this->config());
        $this->assertSame('supported', $results[0]->status);
        $this->assertSame(['file_count' => 1], $results[0]->evidence);
        $this->assertSame('unknown', $results[1]->status);
        $this->assertSame('unsupported', $results[2]->status);
        $this->assertSame('database_backup', $results[2]->capability);
        $this->assertSame(0, $fake->reads);
        config(['opshub.live_connectors_enabled' => false]);
        $result = app(SftpAdapter::class)->readObservation($this->config(), Capability::SftpRead);
        $this->assertSame('NOT_CONFIGURED', $result->reasonCode);
        $this->assertFalse($result->fake);
        $this->assertFalse($result->successful());
    }

    public function test_path_changed_to_symlink_during_read_never_reaches_consumer_and_timeout_is_bounded(): void
    {
        $fake = $this->fake();
        $fake->afterRead = function ($session, $path): void {
            $session->entries[$path]['type'] = 3;
            $session->entries[$path]['canonical'] = '/outside/secret';
        };
        $chunks = 0;
        $consume = function () use (&$chunks): void {
            $chunks++;
        };
        $this->refused(fn () => $this->adapter($fake)->stream($this->config(), '/srv/app', 'a.txt', $consume), ConnectorReason::PathBlocked);
        $this->assertSame(0, $chunks);
        $fake = $this->fake();
        $fake->afterRead = fn () => usleep(1100000);
        $this->refused(fn () => $this->adapter($fake)->stream($this->config(), '/srv/app', 'a.txt', $consume, 3, 1), ConnectorReason::Timeout);
        $this->assertSame(0, $chunks);
        $fake = $this->fake();
        $fake->entries['/srv/app/empty'] = ['type' => 1, 'size' => 0, 'mtime' => 10];
        $manifest = $this->adapter($fake)->stream($this->config(), '/srv/app', 'empty', $consume, 0);
        $this->assertSame(hash('sha256', ''), $manifest['sha256']);
        $this->assertSame(0, $fake->reads);
    }

    public function test_native_authentication_checks_pinned_handshake_before_resolving_any_secret(): void
    {
        $client = \Mockery::mock(SFTP::class);
        $client->shouldReceive('setTimeout')->once()->with(5);
        $client->shouldReceive('getServerPublicHostKey')->once()->andReturn('ssh-ed25519 '.base64_encode('fictitious-host-key'));
        $client->shouldNotReceive('login');
        $secrets = \Mockery::mock(ConnectorSecretResolver::class);
        $secrets->shouldNotReceive('resolve');
        $session = new NativeSftpSession($client, $this->config(), $secrets);
        $this->refused(fn () => $session->authenticate(), ConnectorReason::HostKeyMismatch);
    }
}
