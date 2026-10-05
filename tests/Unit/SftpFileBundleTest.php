<?php

namespace Tests\Unit;

use App\Application\Backups\BackupPolicySettings;
use App\Infrastructure\Backup\SftpFileBundle;
use App\Infrastructure\Backup\TransferBudget;
use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Infrastructure\Connectors\Sftp\RemotePathGuard;
use App\Infrastructure\Connectors\Sftp\SftpAdapter;
use App\Infrastructure\Testing\AdvancingTransferClock;
use App\Infrastructure\Testing\MemorySftpSessions;
use Illuminate\Support\Str;
use Tests\TestCase;

class SftpFileBundleTest extends TestCase
{
    private function bundle(array $entries): array
    {
        $sessions = new MemorySftpSessions(['/srv/app' => ['type' => 2, 'mtime' => 100], ...$entries]);
        $clock = new AdvancingTransferClock;
        $bundle = new SftpFileBundle(new SftpAdapter($sessions, new RemotePathGuard), $clock);
        $config = new ConnectorConfig(1, 1, 'sftp', 'sftp://sftp.example', 'demo', 'env:OPSHUB_CONNECTOR_BUNDLE_TEST', ['/srv/app'], $sessions->pin);

        return [$bundle, $config, app(BackupPolicySettings::class)->defaults(), $sessions, $clock];
    }

    public function test_large_generated_file_streams_over_one_gib_with_bounded_memory_chunk_and_rate(): void
    {
        $size = 1073741824 + 65537;
        [$bundle, $config, $policy, $sessions, $clock] = $this->bundle(['/srv/app/large.bin' => ['type' => 1, 'size' => $size, 'mtime' => 101]]);
        $policy['max_bytes'] = $size + 1048576;
        $policy['bytes_per_second'] = 104857600;
        $manifest = [];
        $bytes = 0;
        $largest = 0;
        $baseline = memory_get_usage(true);
        memory_reset_peak_usage();
        $bundle->stream($config, $policy, (string) Str::uuid(), function ($chunk) use (&$bytes, &$largest): void {
            $bytes += strlen($chunk);
            $largest = max($largest, strlen($chunk));
        }, $manifest);
        $growth = memory_get_peak_usage(true) - $baseline;
        $this->assertSame($size, $manifest['bytes']);
        $this->assertSame($bytes, $manifest['bundle_bytes']);
        $this->assertSame(1, $manifest['file_count']);
        $this->assertSame(['database'], $manifest['coverage_gaps']);
        $this->assertTrue($manifest['fake']);
        $this->assertSame(65536, $sessions->largestRead);
        $this->assertLessThanOrEqual(65536, $largest);
        $this->assertLessThan(16777216, $growth);
        $this->assertGreaterThanOrEqual(($bytes - 65536) / 104857600 - 0.001, $clock->elapsed);
        fwrite(STDOUT, "\nSFTP generated fixture: payload={$size}, bundle={$bytes}, peak_growth={$growth}, max_chunk={$largest}, paced_seconds={$clock->elapsed}\n");
    }

    public function test_private_manifest_preserves_metadata_exclusions_and_empty_files_without_following_excluded_symlink(): void
    {
        [$bundle, $config, $policy, $sessions] = $this->bundle([
            '/srv/app/a.txt' => ['type' => 1, 'size' => 3, 'mtime' => 101, 'content' => 'abc'],
            '/srv/app/empty' => ['type' => 1, 'size' => 0, 'mtime' => 102],
            '/srv/app/cache' => ['type' => 3, 'canonical' => '/outside'],
        ]);
        $policy['excluded_paths'] = ['cache'];
        $manifest = [];
        $stream = '';
        $bundle->stream($config, $policy, (string) Str::uuid(), function ($chunk) use (&$stream): void {
            $stream .= $chunk;
        }, $manifest);
        $this->assertStringStartsWith(SftpFileBundle::MAGIC, $stream);
        $this->assertSame(hash('sha256', $stream), $manifest['sha256']);
        $this->assertSame(['a.txt', 'empty'], array_column($manifest['files'], 'path'));
        $this->assertSame(hash('sha256', 'abc'), $manifest['files'][0]['sha256']);
        $this->assertSame(hash('sha256', ''), $manifest['files'][1]['sha256']);
        $this->assertSame(101, $manifest['files'][0]['mtime']);
        $this->assertSame([['root_index' => 0, 'path' => 'cache', 'reason' => 'policy']], $manifest['exclusions']);
        $this->assertSame([], $manifest['errors']);
        $this->assertSame(1, $sessions->reads);
    }

    public function test_required_missing_changed_or_unreadable_paths_never_produce_complete_bundle(): void
    {
        foreach (['missing', 'changed', 'permission', 'symlink', 'directory_changed'] as $case) {
            [$bundle, $config, $policy, $sessions] = $this->bundle(['/srv/app/a' => ['type' => 1, 'size' => 131073, 'mtime' => 100]]);
            if ($case === 'missing') {
                $policy['included_paths'] = [['root_index' => 0, 'path' => 'missing']];
            }
            if ($case === 'permission') {
                $sessions->failure = ConnectorReason::PermissionDenied;
            }
            if ($case === 'symlink') {
                $sessions->entries['/srv/app/a']['type'] = 3;
            }
            if ($case === 'changed') {
                $sessions->afterRead = function ($sessions): void {
                    $sessions->entries['/srv/app/a']['mtime']++;
                };
            }
            if ($case === 'directory_changed') {
                $sessions->afterRead = function ($sessions): void {
                    $sessions->entries['/srv/app']['mtime']++;
                };
            }
            $manifest = [];
            try {
                $bundle->stream($config, $policy, (string) Str::uuid(), fn () => null, $manifest);
                $this->fail($case.' unexpectedly succeeded');
            } catch (ConnectorFailure) {
                $this->assertNotEmpty($manifest['errors'], $case);
                $this->assertArrayNotHasKey('sha256', $manifest);
            }
        }
    }

    public function test_byte_rate_and_deadline_are_one_cumulative_budget(): void
    {
        $clock = new AdvancingTransferClock;
        $budget = new TransferBudget($clock, 65536, 200000, 1);
        $count = 0;
        $consume = function ($chunk) use (&$count): void {
            $count += strlen($chunk);
        };
        $budget->consume(str_repeat('x', 65536), $consume);
        $budget->consume(str_repeat('x', 32768), $consume);
        $this->assertEqualsWithDelta(0.5, $clock->elapsed, 0.00001);
        try {
            $budget->consume(str_repeat('x', 65536), $consume);
            $this->fail('Deadline unexpectedly passed');
        } catch (ConnectorFailure $error) {
            $this->assertSame(ConnectorReason::Timeout, $error->reason);
            $this->assertSame(98304, $count);
        }
        $budget = new TransferBudget(new AdvancingTransferClock, 65536, 5, 30);
        $this->expectException(ConnectorFailure::class);
        $budget->consume('123456', $consume);
    }
}
