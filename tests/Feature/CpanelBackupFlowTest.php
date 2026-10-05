<?php

namespace Tests\Feature;

use App\Application\Backups\BackupPolicies;
use App\Application\Backups\BackupRuns;
use App\Application\Backups\CpanelBackupFlow;
use App\Infrastructure\Backup\BackupSink;
use App\Infrastructure\Backup\CpanelBackupSource;
use App\Infrastructure\Backup\SourceAcceptance;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Infrastructure\Testing\ScriptedCpanelBackupSource;
use App\Infrastructure\Testing\SpoolingBackupSink;
use App\Models\BackupSourceOperation;
use App\Models\ManagementAuthorization;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BackupFixture;
use Tests\Support\RenewalFixture;
use Tests\Support\TelegramFixture;
use Tests\TestCase;

class CpanelBackupFlowTest extends TestCase
{
    use BackupFixture, RefreshDatabase, RenewalFixture, TelegramFixture;

    private function adapters(): array
    {
        $source = new ScriptedCpanelBackupSource;
        $sink = new SpoolingBackupSink;
        app()->instance(CpanelBackupSource::class, $source);
        app()->instance(BackupSink::class, $sink);

        return [$source, $sink, app(CpanelBackupFlow::class)];
    }

    public function test_acceptance_is_awaiting_source_then_stable_retrieval_is_unverified_not_success(): void
    {
        [, , $run] = $this->backupGraph();
        [$source, $sink, $flow] = $this->adapters();
        $flow->execute($run->id);
        $this->assertSame('awaiting_source', $run->fresh()->state);
        $this->assertSame('accepted', $run->fresh()->source_status);
        $this->assertSame('none', $run->fresh()->integrity_status);
        $flow->execute($run->id);
        $this->assertSame(1, $source->requests);
        $this->assertSame(0, $source->observations);
        $source->observedState = 'ready';
        $flow->execute($run->id, true);
        $this->assertSame('stabilizing', $run->fresh()->source_status);
        $this->assertSame(0, $source->streams);
        $this->travel(31)->seconds();
        $flow->execute($run->id);
        $this->assertSame('verifying', $run->fresh()->state);
        $this->assertSame('stored_unverified', $run->fresh()->transfer_status);
        $this->assertSame('none', $run->fresh()->integrity_status);
        $this->assertSame(1, $source->streams);
        $this->assertSame(131073, $sink->bytes);
        $this->assertLessThanOrEqual(65536, $sink->largestChunk);
        $this->assertSame(0, OutboxEvent::where('event_type', 'backup.verified')->count());
        $this->assertNotNull($run->fresh()->active_account_id);
        $this->assertArrayNotHasKey('artifact_locator', BackupSourceOperation::where('backup_run_id', $run->id)->sole()->toArray());
    }

    public function test_uncertain_or_crashed_request_reconciles_first_and_never_creates_second_remote_backup(): void
    {
        [, , $run] = $this->backupGraph();
        [$source, , $flow] = $this->adapters();
        $source->acceptance = new SourceAcceptance('uncertain', null, ConnectorReason::Timeout, true);
        $flow->execute($run->id);
        $this->assertSame('reconcile_required', $run->fresh()->state);
        $source->observedState = 'unknown';
        $flow->execute($run->id, true);
        $flow->execute($run->id, true);
        $this->assertSame(1, $source->requests);
        $this->assertSame(2, $source->observations);
        $this->assertNotNull($run->fresh()->active_account_id);
        $this->assertDatabaseCount('backup_source_requests', 1);
        $source->observedState = 'idle_proven';
        $flow->execute($run->id, true);
        $this->assertSame('queued', $run->fresh()->state);
        app(BackupRuns::class)->preflight($run->id);
        $source->acceptance = null;
        $flow->execute($run->id);
        $this->assertSame(2, $source->requests);
        $this->assertDatabaseCount('backup_source_requests', 2);
        $this->assertSame('awaiting_source', $run->fresh()->state);
    }

    public function test_expired_request_lease_and_provider_busy_deadline_keep_fence_without_retrigger(): void
    {
        [, , $run] = $this->backupGraph();
        [$source, , $flow] = $this->adapters();
        $flow->execute($run->id);
        $run->refresh()->update(['lease_owner' => 'fictitious-crashed-worker', 'leased_until' => now()->subSecond()]);
        app(BackupRuns::class)->recoverExpired($run->id);
        $flow->execute($run->id, true);
        $this->assertSame(1, $source->requests);
        $this->assertSame('awaiting_source', $run->fresh()->state);
        BackupSourceOperation::where('backup_run_id', $run->id)->update(['deadline_at' => now('UTC')->subSecond()]);
        $flow->execute($run->id, true);
        $this->assertSame('reconcile_required', $run->fresh()->state);
        $this->assertNotNull($run->fresh()->active_account_id);
        $this->assertSame(1, $source->requests);
    }

    public function test_wrong_association_or_incomplete_source_is_never_retrieved_and_kill_switch_fences_transfer(): void
    {
        [$org, $owner, $run] = $this->backupGraph();
        [$source, $sink, $flow] = $this->adapters();
        $flow->execute($run->id);
        $source->observedState = 'ready';
        $source->completionProven = false;
        $flow->execute($run->id, true);
        $this->assertSame(0, $source->streams);
        $source->completionProven = true;
        $source->wrongAssociation = true;
        $flow->execute($run->id, true);
        $this->assertSame('reconcile_required', $run->fresh()->state);
        $this->assertSame(0, $source->streams);
        $source->wrongAssociation = false;
        $flow->execute($run->id, true);
        $this->travel(31)->seconds();
        $source->duringStream = fn () => app(BackupPolicies::class)->setPaused($org, $owner, true, 1);
        $flow->execute($run->id, true);
        $this->assertSame('reconcile_required', $run->fresh()->state);
        $this->assertSame(0, $sink->bytes);
        $this->assertNotNull($run->fresh()->active_account_id);
        $this->assertSame(0, OutboxEvent::where('event_type', 'backup.verified')->count());
    }

    public function test_rejected_auth_pauses_connector_creates_sanitized_failure_and_no_uptime_incident(): void
    {
        [, , $run, $connector] = $this->backupGraph();
        [$source, , $flow] = $this->adapters();
        $source->acceptance = new SourceAcceptance('rejected', null, ConnectorReason::AuthFailed, true);
        $flow->execute($run->id);
        $this->assertSame('failed', $run->fresh()->state);
        $this->assertNull($run->fresh()->active_account_id);
        $this->assertSame('auth_failed', $connector->fresh()->state);
        $this->assertTrue($connector->fresh()->writes_paused);
        $this->assertSame(1, OutboxEvent::where('event_type', 'backup.failed')->count());
        $this->assertSame(1, OutboxEvent::where('event_type', 'connector.failed')->count());
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_native_unconfigured_retrieval_path_blocks_before_trigger_without_fixture_fallback(): void
    {
        [, , $run] = $this->backupGraph();
        app(CpanelBackupFlow::class)->execute($run->id);
        $this->assertSame('blocked', $run->fresh()->state);
        $this->assertSame('NOT_CONFIGURED', $run->fresh()->reason_code);
        $this->assertDatabaseCount('backup_source_operations', 0);
        $this->assertDatabaseCount('backup_source_requests', 0);
    }

    public function test_authorization_cutoff_and_model_storage_are_utc_with_display_timezone_jakarta(): void
    {
        $this->freezeTime();
        [, , $run, $connector] = $this->backupGraph();
        [$source, , $flow] = $this->adapters();
        $authorization = ManagementAuthorization::where('resource_id', $connector->hosting_account_id)->sole();
        $authorization->update(['valid_until' => now('UTC')->addMinutes(30)]);
        $flow->execute($run->id);
        $this->assertSame('awaiting_source', $run->fresh()->state);
        $authorization->update(['valid_until' => now('Asia/Jakarta')->addMinutes(30)]);
        $this->assertSame(now('UTC')->addMinutes(30)->format('Y-m-d H:i:s'), DB::table('management_authorizations')->where('id', $authorization->id)->value('valid_until'));
        $this->travel(31)->minutes();
        $flow->execute($run->id, true);
        $this->assertSame('reconcile_required', $run->fresh()->state);
        $this->assertSame('AUTHORIZATION_EXPIRED', $run->fresh()->reason_code);
        $this->assertSame(1, $source->requests);
        $this->assertSame(0, $source->observations);
    }
}
