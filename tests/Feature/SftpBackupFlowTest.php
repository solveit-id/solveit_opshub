<?php

namespace Tests\Feature;

use App\Application\Backups\BackupPolicies;
use App\Application\Backups\BackupRuns;
use App\Application\Backups\SftpBackupFlow;
use App\Infrastructure\Backup\BackupSink;
use App\Infrastructure\Backup\TransferClock;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Infrastructure\Connectors\Sftp\SftpSessionFactory;
use App\Infrastructure\Testing\AdvancingTransferClock;
use App\Infrastructure\Testing\MemorySftpSessions;
use App\Infrastructure\Testing\SpoolingBackupSink;
use App\Models\ManagementAuthorization;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BackupFixture;
use Tests\Support\RenewalFixture;
use Tests\Support\TelegramFixture;
use Tests\TestCase;

class SftpBackupFlowTest extends TestCase
{
    use BackupFixture, RefreshDatabase, RenewalFixture, TelegramFixture;

    private function adapters(): array
    {
        $source = new MemorySftpSessions(['/srv/app' => ['type' => 2, 'mtime' => 100], '/srv/app/config.php' => ['type' => 1, 'size' => 131073, 'mtime' => 101]]);
        $sink = new SpoolingBackupSink;
        app()->instance(SftpSessionFactory::class, $source);
        app()->instance(BackupSink::class, $sink);
        app()->instance(TransferClock::class, new AdvancingTransferClock);

        return [$source, $sink];
    }

    public function test_queued_private_stream_has_manifest_and_database_gap_without_success_or_paths_in_response(): void
    {
        [$org, , $run] = $this->backupGraph('sftp');
        [$source, $sink] = $this->adapters();
        $this->assertTrue(DB::table('jobs')->where('queue', 'backup')->count() >= 2);
        app(SftpBackupFlow::class)->execute($run->id);
        $run->refresh();
        $this->assertSame('verifying', $run->state);
        $this->assertSame('stored_unverified', $run->transfer_status);
        $this->assertSame('none', $run->integrity_status);
        $this->assertSame(['database'], $run->transfer_manifest['coverage_gaps']);
        $this->assertSame(131073, $run->transferred_bytes);
        $this->assertSame(1, $run->transferred_files);
        $this->assertSame($sink->bytes, $run->transfer_manifest['bundle_bytes']);
        $this->assertLessThanOrEqual(65536, $sink->largestChunk);
        $reads = $source->reads;
        app(SftpBackupFlow::class)->execute($run->id);
        $this->assertSame($reads, $source->reads);
        $response = $this->getJson('/api/v1/organizations/'.$org->id.'/backup-runs/'.$run->id)->assertOk();
        $this->assertStringNotContainsString('config.php', $response->getContent());
        $this->assertStringNotContainsString('transfer_manifest', $response->getContent());
    }

    public function test_partial_changed_or_denied_stream_keeps_fence_and_sanitized_failure_without_green_or_outage(): void
    {
        [$org, , $run, $connector] = $this->backupGraph('sftp');
        [$source] = $this->adapters();
        $source->afterRead = function ($source): void {
            $source->entries['/srv/app/config.php']['mtime']++;
        };
        app(SftpBackupFlow::class)->execute($run->id);
        $run->refresh();
        $this->assertSame('reconcile_required', $run->state);
        $this->assertSame('partial', $run->source_status);
        $this->assertSame('ARTIFACT_INCOMPLETE', $run->reason_code);
        $this->assertSame(1, $run->transfer_errors);
        $this->assertNotNull($run->active_account_id);
        $this->assertTrue(OutboxEvent::where('event_type', 'backup.failed')->exists());
        $this->assertSame(0, DB::table('observations')->count());
        $this->assertSame(0, DB::table('incidents')->count());
        // Explicit fixture reset to test a separate rejected pre-transfer scenario.
        $run->refresh()->update(['state' => 'ready']);
        $source->afterRead = null;
        $source->failure = ConnectorReason::PermissionDenied;
        app(SftpBackupFlow::class)->execute($run->id);
        $this->assertSame('partial', $run->fresh()->transfer_status);
        $this->assertTrue($connector->fresh()->writes_paused);
        $this->assertNotSame('succeeded', $run->fresh()->state);
    }

    public function test_scope_expiry_kill_switch_and_stale_capacity_deny_before_source_read(): void
    {
        [$org, $owner, $run] = $this->backupGraph('sftp');
        [$source] = $this->adapters();
        ManagementAuthorization::query()->update(['valid_until' => now('UTC')->subSecond()]);
        app(SftpBackupFlow::class)->execute($run->id);
        $this->assertSame('blocked', $run->fresh()->state);
        $this->assertSame(0, $source->authentications);
        ManagementAuthorization::query()->update(['valid_until' => now('UTC')->addDay()]);
        $run->refresh()->update(['state' => 'ready']);
        app(BackupPolicies::class)->setPaused($org, $owner, true, 1);
        app(SftpBackupFlow::class)->execute($run->id);
        $this->assertSame('reconcile_required', $run->fresh()->state);
        $this->assertSame(0, $source->reads);
        app(BackupPolicies::class)->setPaused($org, $owner, false, 2);
        $evidence = $run->refresh()->preflight_evidence;
        $evidence['observed_at'] = now('UTC')->subMinutes(6)->toIso8601String();
        $run->update(['state' => 'ready', 'preflight_evidence' => $evidence]);
        app(SftpBackupFlow::class)->execute($run->id);
        $this->assertSame('CAPACITY_UNKNOWN', $run->fresh()->reason_code);
        $this->assertSame(0, $source->reads);
    }

    public function test_midflight_pause_and_expired_lease_fence_late_worker_and_no_automatic_restart(): void
    {
        [$org, $owner, $run] = $this->backupGraph('sftp');
        [$source, $sink] = $this->adapters();
        $source->afterRead = fn () => app(BackupPolicies::class)->setPaused($org, $owner, true, 1);
        app(SftpBackupFlow::class)->execute($run->id);
        $this->assertSame('reconcile_required', $run->fresh()->state);
        $this->assertNotSame('stored_unverified', $run->fresh()->transfer_status);
        $this->assertGreaterThan(0, $sink->bytes); // Metadata already written, whole temporary sink is discarded.
        $reads = $source->reads;
        app(SftpBackupFlow::class)->execute($run->id);
        $this->assertSame($reads, $source->reads);
        app(BackupPolicies::class)->setPaused($org, $owner, false, 2);
        $run->update(['state' => 'transferring', 'lease_owner' => 'fictitious-expired', 'leased_until' => now('UTC')->subSecond()]);
        app(BackupRuns::class)->recoverExpired($run->id);
        app(SftpBackupFlow::class)->execute($run->id);
        $this->assertSame($reads, $source->reads);
        $this->assertSame('reconcile_required', $run->fresh()->state);
    }

    public function test_native_gate_and_global_limit_block_without_source_connection(): void
    {
        [, , $run] = $this->backupGraph('sftp');
        app(SftpBackupFlow::class)->execute($run->id);
        $this->assertSame('NOT_CONFIGURED', $run->fresh()->reason_code);
        [$source] = $this->adapters();
        $run->refresh()->update(['state' => 'ready']);
        DB::table('backup_execution_controls')->where('id', 1)->update(['maximum_parallel' => 1]);
        // Competing global slot represented by another real canonical account/run.
        [$org2, , $other] = $this->backupGraph('sftp');
        $other->update(['state' => 'transferring']);
        app(SftpBackupFlow::class)->execute($run->id);
        $this->assertSame('ready', $run->fresh()->state);
        $this->assertSame(0, $source->authentications);
        $jobs = DB::table('jobs')->count();
        app(SftpBackupFlow::class)->execute($run->id);
        $this->assertSame($jobs, DB::table('jobs')->count());
    }
}
