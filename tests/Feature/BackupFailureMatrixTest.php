<?php

namespace Tests\Feature;

use App\Application\Backups\BackupPolicies;
use App\Application\Backups\BackupRetention;
use App\Application\Backups\BackupRuns;
use App\Application\Backups\BackupVerification;
use App\Application\Backups\SftpBackupFlow;
use App\Infrastructure\Backup\BackupDeletionPermit;
use App\Infrastructure\Backup\TemporarySourceCleaner;
use App\Infrastructure\Backup\TransferClock;
use App\Infrastructure\Testing\AdvancingTransferClock;
use App\Models\BackupArtifact;
use App\Models\BackupRun;
use App\Models\ManagementAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BackupFixture;
use Tests\Support\RenewalFixture;
use Tests\Support\TelegramFixture;
use Tests\Support\VerifiedBackupFixture;
use Tests\TestCase;

class BackupFailureMatrixTest extends TestCase
{
    use BackupFixture, RefreshDatabase, RenewalFixture, TelegramFixture, VerifiedBackupFixture;

    public static function cleanupChanges(): array
    {
        return [['kill_switch'], ['authorization'], ['connector_version'], ['policy_version'], ['ownership']];
    }

    #[DataProvider('cleanupChanges')]
    public function test_source_cleanup_rechecks_intent_permission_policy_kill_and_ownership_at_write_boundary(string $change): void
    {
        [$org, $owner, $run, $connector, $policy, $artifact] = $this->verifiedBackup();
        app(BackupPolicies::class)->configure($org, $owner, $connector, [...$policy->configuration, 'cleanup_temporary_source' => true], true, 1);
        $connector->update(['kind' => 'cpanel']); // Explicit ownership protocol fixture; no provider connection.
        $run->update(['source_status' => 'retrieved']);
        $cleaner = $this->cleaner();
        $cleaner->beforeDelete = function () use ($change, $org, $owner, $connector, $policy, $cleaner): void {
            match ($change) {
                'kill_switch' => app(BackupPolicies::class)->setPaused($org, $owner, true, 1),
                'authorization' => ManagementAuthorization::where('resource_type', 'hosting_account')->update(['valid_until' => now('UTC')->subSecond()]),
                'connector_version' => $connector->increment('version'),
                'policy_version' => $policy->increment('version'),
                'ownership' => $cleaner->owned = false,
            };
        };
        app()->instance(TemporarySourceCleaner::class, $cleaner);
        $url = '/api/v1/organizations/'.$org->id.'/backup-artifacts/'.$artifact->id.'/cleanup-source';
        $this->postJson($url)->assertOk()->assertJsonPath('data.state', 'blocked');
        $this->assertSame(0, $cleaner->deletes);
        $this->assertSame('verified', $artifact->fresh()->state);
        $this->postJson($url)->assertConflict(); // Never automatically retry a persisted cleanup intent.
        $this->assertSame(0, $cleaner->deletes);
        $this->assertSame('blocked', DB::table('audit_events')->where('action', 'backup.temporary_source.cleanup')->sole()->outcome);
        $this->assertSame(0, DB::table('incidents')->count());
    }

    public function test_cleanup_effect_then_provider_error_is_unknown_and_not_reissued_or_leaked(): void
    {
        [$org, $owner, $run, $connector, $policy, $artifact] = $this->verifiedBackup();
        app(BackupPolicies::class)->configure($org, $owner, $connector, [...$policy->configuration, 'cleanup_temporary_source' => true], true, 1);
        $connector->update(['kind' => 'cpanel']);
        $run->update(['source_status' => 'retrieved']);
        $cleaner = $this->cleaner();
        $cleaner->afterDelete = fn () => throw new \RuntimeException('password=must-not-leak https://private.invalid/?token=must-not-leak');
        app()->instance(TemporarySourceCleaner::class, $cleaner);
        $url = '/api/v1/organizations/'.$org->id.'/backup-artifacts/'.$artifact->id.'/cleanup-source';
        $this->postJson($url)->assertOk()->assertJsonPath('data.state', 'unknown');
        $this->postJson($url)->assertConflict();
        $this->assertSame(1, $cleaner->deletes);
        $this->assertStringNotContainsString('must-not-leak', DB::table('audit_events')->get()->toJson());
        $this->assertStringNotContainsString('must-not-leak', DB::table('outbox_events')->get()->toJson());
        $this->assertSame('verified', $artifact->fresh()->state);
    }

    public function test_retention_preserves_each_effect_and_stops_before_worker_budget_without_second_delete(): void
    {
        [$org, $owner, , $connector, $policy, $first, $store] = $this->verifiedBackup();
        $policy = app(BackupPolicies::class)->configure($org, $owner, $connector, [...$policy->configuration, 'retention' => ['daily' => 1, 'weekly' => 1, 'monthly' => 1]], true, 1);
        $artifacts = [$first];
        for ($i = 1; $i <= 2; $i++) {
            $this->travel(2)->seconds();
            $run = app(BackupRuns::class)->enqueue($org, $owner, $policy, 2, 'bounded-retention-'.$i);
            app(BackupRuns::class)->preflight($run->id);
            app(SftpBackupFlow::class)->execute($run->id);
            app(BackupVerification::class)->execute($run->id);
            $artifacts[] = BackupArtifact::where('backup_run_id', $run->id)->sole();
        }
        $artifacts[0]->update(['source_observed_at' => now('UTC')->subYear()]);
        $artifacts[1]->update(['source_observed_at' => now('UTC')->subMonths(6)]);
        $clock = app(TransferClock::class);
        $this->assertInstanceOf(AdvancingTransferClock::class, $clock);
        $store->afterDelete = fn () => $clock->wait(45);
        $retention = app(BackupRetention::class);
        $report = $retention->dryRun($org, $owner, $policy);
        $retention->enqueue($org, $owner, $report);
        $retention->apply($report->id);
        $retention->apply($report->id);
        $this->assertSame('partial', $report->fresh()->state);
        $this->assertCount(1, $report->fresh()->results);
        $this->assertSame('deleted', $artifacts[0]->fresh()->state);
        $this->assertSame('verified', $artifacts[1]->fresh()->state);
        $this->assertSame('present', $store->presence($artifacts[1]->object_reference, $artifacts[1]->object_version));
        $this->assertSame($artifacts[2]->id, DB::table('backup_scope_goods')->value('backup_artifact_id'));
    }

    public static function retentionChanges(): array
    {
        return [['kill_switch'], ['legal_hold'], ['expired_reconciled_lease']];
    }

    #[DataProvider('retentionChanges')]
    public function test_retention_adapter_checks_fresh_controls_and_active_delete_cannot_be_reconciled(string $change): void
    {
        [$org, $owner, , $connector, $policy, $old, $store] = $this->verifiedBackup();
        $policy = app(BackupPolicies::class)->configure($org, $owner, $connector, [...$policy->configuration, 'retention' => ['daily' => 1, 'weekly' => 1, 'monthly' => 1]], true, 1);
        $this->travel(2)->seconds();
        $run = app(BackupRuns::class)->enqueue($org, $owner, $policy, 2, 'retention-late-guard');
        app(BackupRuns::class)->preflight($run->id);
        app(SftpBackupFlow::class)->execute($run->id);
        app(BackupVerification::class)->execute($run->id);
        $old->update(['source_observed_at' => now('UTC')->subYear()]);
        $retention = app(BackupRetention::class);
        $report = $retention->dryRun($org, $owner, $policy);
        $retention->enqueue($org, $owner, $report);
        $store->beforeDelete = function () use ($change, $org, $owner, $old, $retention): void {
            $old->refresh();
            $this->postJson('/api/v1/organizations/'.$org->id.'/backup-artifacts/'.$old->id.'/reconcile-deletion', ['version' => $old->version])->assertConflict();
            if ($change === 'kill_switch') {
                app(BackupPolicies::class)->setPaused($org, $owner, true, 1);
            } elseif ($change === 'legal_hold') {
                $old->update(['legal_hold' => true]);
            } else {
                $old->update(['delete_leased_until' => now('UTC')->subSecond()]);
                $this->assertSame('verified', $retention->reconcile($org, $owner, $old, $old->version)->state);
            }
        };
        $retention->apply($report->id);
        $this->assertSame('verified', $old->fresh()->state);
        $this->assertSame('present', $store->presence($old->object_reference, $old->object_version));
        $this->assertNull($old->fresh()->deleted_at);
        $this->assertNull($old->fresh()->delete_lease);
        $this->assertSame('verified', $report->fresh()->results[0]['state']);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'backup.retention.deleted')->where('outcome', 'deleted')->count());
    }

    private function cleaner(): object
    {
        return new class implements TemporarySourceCleaner
        {
            public bool $owned = true;

            public int $deletes = 0;

            public ?\Closure $beforeDelete = null;

            public ?\Closure $afterDelete = null;

            public function fake(): bool
            {
                return true;
            }

            public function owned(BackupRun $run, BackupArtifact $artifact): bool
            {
                return $this->owned;
            }

            public function deleteOwned(BackupRun $run, BackupArtifact $artifact, BackupDeletionPermit $permit): void
            {
                if ($this->beforeDelete) {
                    ($this->beforeDelete)();
                }
                $permit->authorize();
                $this->deletes++;
                if ($this->afterDelete) {
                    ($this->afterDelete)();
                }
            }
        };
    }
}
