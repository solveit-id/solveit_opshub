<?php

namespace Tests\Feature;

use App\Application\Backups\BackupPolicies;
use App\Application\Backups\BackupRuns;
use App\Application\Backups\BackupVerification;
use App\Application\Backups\CpanelBackupFlow;
use App\Application\Backups\SftpBackupFlow;
use App\Infrastructure\Backup\BackupKeyResolver;
use App\Infrastructure\Backup\CpanelBackupSource;
use App\Infrastructure\Backup\PrivateObjectStore;
use App\Infrastructure\Backup\TransferClock;
use App\Infrastructure\Connectors\Sftp\SftpSessionFactory;
use App\Infrastructure\Testing\AdvancingTransferClock;
use App\Infrastructure\Testing\MemorySftpSessions;
use App\Infrastructure\Testing\RandomBackupKeys;
use App\Infrastructure\Testing\ScriptedCpanelBackupSource;
use App\Infrastructure\Testing\SpoolingPrivateObjectStore;
use App\Models\BackupArtifact;
use App\Models\BackupPolicy;
use App\Models\ManagementAuthorization;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BackupFixture;
use Tests\Support\RenewalFixture;
use Tests\Support\TelegramFixture;
use Tests\TestCase;

class BackupVerificationTest extends TestCase
{
    use BackupFixture, RefreshDatabase, RenewalFixture, TelegramFixture;

    private function adapters(): array
    {
        $source = new MemorySftpSessions(['/srv/app' => ['type' => 2, 'mtime' => 100], '/srv/app/config.php' => ['type' => 1, 'size' => 131073, 'mtime' => 101]]);
        $store = new SpoolingPrivateObjectStore;
        $keys = new RandomBackupKeys;
        app()->instance(SftpSessionFactory::class, $source);
        app()->instance(PrivateObjectStore::class, $store);
        app()->instance(BackupKeyResolver::class, $keys);
        app()->instance(TransferClock::class, new AdvancingTransferClock);

        return [$source, $store, $keys];
    }

    public function test_authenticated_private_version_content_verified_files_keep_database_gap_and_scoped_last_good(): void
    {
        [$org, , $run] = $this->backupGraph('sftp');
        [, $store, $keys] = $this->adapters();
        app(SftpBackupFlow::class)->execute($run->id);
        $artifact = BackupArtifact::where('backup_run_id', $run->id)->sole();
        $this->assertSame('stored', $artifact->state);
        $this->assertSame('none', $artifact->verification_level);
        $this->assertSame('verifying', $run->fresh()->state);
        $raw = $store->read($artifact->object_reference, $artifact->object_version);
        $bytes = stream_get_contents($raw);
        fclose($raw);
        $this->assertStringNotContainsString('config.php', $bytes);
        $this->assertStringNotContainsString($keys->key, $bytes);
        app(BackupVerification::class)->execute($run->id);
        $artifact->refresh();
        $run->refresh();
        $this->assertSame('verified', $artifact->state);
        $this->assertSame('content_verified', $artifact->verification_level);
        $this->assertSame(['files'], $artifact->coverage_scopes);
        $this->assertSame('partial', $run->state);
        $this->assertSame('COVERAGE_GAP', $run->reason_code);
        $this->assertSame('independent_verified', $run->transfer_status);
        $this->assertNull($run->active_account_id);
        $this->assertSame(['files'], DB::table('backup_scope_goods')->pluck('scope')->all());
        $this->assertSame($artifact->id, DB::table('backup_scope_goods')->value('backup_artifact_id'));
        $this->assertSame(2, DB::table('backup_verifications')->where('state', 'passed')->count());
        $this->assertArrayNotHasKey('manifest', $artifact->toArray());
        $this->assertArrayNotHasKey('object_reference', $artifact->toArray());
        $this->assertArrayNotHasKey('key_reference', $artifact->toArray());
        $this->assertSame(0, DB::table('incidents')->count()); // No website outage invented.
        $this->assertTrue(OutboxEvent::where('event_type', 'backup.partial')->exists());
    }

    public function test_checksum_tamper_or_wrong_key_fails_without_advancing_existing_last_good(): void
    {
        [$org, $owner, $first, , $policy] = $this->backupGraph('sftp');
        [, $store, $keys] = $this->adapters();
        app(SftpBackupFlow::class)->execute($first->id);
        app(BackupVerification::class)->execute($first->id);
        $old = (array) DB::table('backup_scope_goods')->sole();
        foreach (['checksum', 'aead', 'key'] as $case) {
            $run = app(BackupRuns::class)->enqueue($org, $owner, $policy, 1, 'second-fixture-'.$case);
            app(BackupRuns::class)->preflight($run->id);
            app(SftpBackupFlow::class)->execute($run->id);
            $artifact = BackupArtifact::where('backup_run_id', $run->id)->sole();
            if ($case === 'key') {
                $keys->key = random_bytes(32);
            } else {
                $stream = $store->objects[$artifact->object_reference.'/'.$artifact->object_version];
                fseek($stream, 80);
                $value = fread($stream, 1);
                fseek($stream, 80);
                fwrite($stream, chr(ord($value) ^ 1));
                fflush($stream);
                if ($case === 'aead') {
                    $artifact->update(['encrypted_sha256' => $store->metadata($artifact->object_reference, $artifact->object_version)['sha256']]);
                }
            }
            app(BackupVerification::class)->execute($run->id);
            $this->assertSame('failed', $run->fresh()->state, $case);
            $this->assertSame('INTEGRITY_FAILED', $run->fresh()->reason_code, $case);
            $this->assertSame('failed', $artifact->fresh()->state, $case);
            $this->assertSame($old, (array) DB::table('backup_scope_goods')->sole(), $case);
        }
        $this->assertSame(3, DB::table('backup_verifications')->where('state', 'failed')->count());
        $this->assertTrue(OutboxEvent::where('event_type', 'backup.failed')->exists());
    }

    public function test_non_independent_private_or_transport_unprotected_storage_is_denied_before_upload(): void
    {
        [, , $run] = $this->backupGraph('sftp');
        [$source, $store] = $this->adapters();
        foreach (['independent', 'private', 'transportProtected'] as $guard) {
            $run->refresh()->update(['state' => 'ready']);
            $store->$guard = false;
            app(SftpBackupFlow::class)->execute($run->id);
            $this->assertSame('NOT_CONFIGURED', $run->fresh()->reason_code);
            $this->assertCount(0, $store->objects);
            $this->assertSame(0, $source->reads);
            $this->assertSame(0, BackupArtifact::count());
            $store->$guard = true;
        }
    }

    public function test_temporarily_missing_key_or_expired_permission_stays_unknown_until_authorized_reconciliation(): void
    {
        [, , $run] = $this->backupGraph('sftp');
        [, , $keys] = $this->adapters();
        app(SftpBackupFlow::class)->execute($run->id);
        $keys->unavailable = true;
        app(BackupVerification::class)->execute($run->id);
        $this->assertSame('reconcile_required', $run->fresh()->state);
        $this->assertSame('unknown', $run->fresh()->integrity_status);
        $this->assertSame(0, DB::table('backup_scope_goods')->count());
        $keys->unavailable = false;
        ManagementAuthorization::query()->update(['valid_until' => now('UTC')->subSecond()]);
        app(BackupVerification::class)->execute($run->id, true);
        $this->assertSame('AUTHORIZATION_EXPIRED', $run->fresh()->reason_code);
        $this->assertSame(0, DB::table('backup_scope_goods')->count());
        ManagementAuthorization::query()->update(['valid_until' => now('UTC')->addDay()]);
        app(BackupVerification::class)->execute($run->id, true);
        $this->assertSame('content_verified', $run->fresh()->integrity_status);
        $this->assertSame(['files'], DB::table('backup_scope_goods')->pluck('scope')->all());
    }

    public function test_late_upload_after_pause_retains_private_version_for_read_reconcile_but_does_not_promote(): void
    {
        [$org, $owner, $run] = $this->backupGraph('sftp');
        [, $store] = $this->adapters();
        $store->afterCommit = fn () => app(BackupPolicies::class)->setPaused($org, $owner, true, 1);
        app(SftpBackupFlow::class)->execute($run->id);
        $this->assertSame('reconcile_required', $run->fresh()->state);
        $this->assertSame('stored', BackupArtifact::where('backup_run_id', $run->id)->value('state'));
        $this->assertSame(0, DB::table('backup_scope_goods')->count());
        app(BackupVerification::class)->execute($run->id); // Auto worker cannot adopt late result.
        $this->assertSame(0, DB::table('backup_scope_goods')->count());
        app(BackupVerification::class)->execute($run->id, true); // Read reconciliation stays possible while write paused.
        $this->assertSame('partial', $run->fresh()->state);
        $this->assertTrue(app(BackupPolicies::class)->paused($org->id));
    }

    public function test_crash_after_immutable_commit_recovers_only_authenticated_owned_version_without_second_upload(): void
    {
        [, , $run] = $this->backupGraph('sftp');
        [$source, $store, $keys] = $this->adapters();
        $store->afterCommit = fn () => throw new \RuntimeException('Fictitious provider error with private content');
        app(SftpBackupFlow::class)->execute($run->id);
        $artifact = BackupArtifact::where('backup_run_id', $run->id)->sole();
        $this->assertSame('failed', $artifact->state);
        $this->assertNull($artifact->manifest);
        $this->assertCount(1, $store->objects);
        $this->assertSame('reconcile_required', $run->fresh()->state);
        $reads = $source->reads;
        app(SftpBackupFlow::class)->execute($run->id);
        $this->assertSame($reads, $source->reads);
        $keys->unavailable = true;
        app(BackupVerification::class)->execute($run->id, true);
        $this->assertSame('uploading', $artifact->fresh()->state);
        $keys->unavailable = false;
        app(BackupVerification::class)->execute($run->id, true);
        $this->assertSame('partial', $run->fresh()->state);
        $this->assertSame('content_verified', $artifact->fresh()->verification_level);
        $this->assertCount(1, $store->objects);
        $this->assertSame($reads, $source->reads);
        $this->assertStringNotContainsString('private content', $run->fresh()->toJson());
    }

    public function test_step_up_reconcile_enqueues_private_verification_or_closes_read_only_run_with_no_object_intent(): void
    {
        [$org, $owner, $run] = $this->backupGraph('sftp');
        $this->adapters();
        app(SftpBackupFlow::class)->execute($run->id);
        $run->refresh()->update(['state' => 'reconcile_required']);
        $url = '/api/v1/organizations/'.$org->id.'/backup-runs/'.$run->id.'/reconcile';
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp])->postJson($url)->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson($url)->assertAccepted();
        $this->postJson($url)->assertStatus(429);
        app(BackupVerification::class)->execute($run->id, true);
        $this->assertSame('partial', $run->fresh()->state);
        $policy = BackupPolicy::findOrFail($run->backup_policy_id);
        $next = app(BackupRuns::class)->enqueue($org, $owner, $policy, 1, 'fixture-no-object-reconcile');
        app(BackupPolicies::class)->setPaused($org, $owner, true, 1);
        $this->postJson('/api/v1/organizations/'.$org->id.'/backup-runs/'.$next->id.'/reconcile')->assertAccepted();
        $this->assertSame('failed', $next->fresh()->state);
        $this->assertSame('NO_OBJECT_INTENT', $next->fresh()->reason_code);
        $this->assertNull($next->fresh()->active_account_id);
    }

    public function test_owner_approved_files_only_policy_can_succeed_after_content_verification(): void
    {
        [$org, $owner, $old, $connector, $policy] = $this->backupGraph('sftp');
        $this->adapters();
        $old->update(['state' => 'cancelled']); // Fictitious queued/preflight fixture, no object or source intent.
        $settings = $policy->configuration;
        $settings['required_scopes'] = ['files'];
        $policy = app(BackupPolicies::class)->configure($org, $owner, $connector, $settings, true, 1);
        $run = app(BackupRuns::class)->enqueue($org, $owner, $policy, 2, 'fixture-files-only-policy');
        app(BackupRuns::class)->preflight($run->id);
        app(SftpBackupFlow::class)->execute($run->id);
        app(BackupVerification::class)->execute($run->id);
        $this->assertSame('succeeded', $run->fresh()->state);
        $this->assertSame('content_verified', $run->fresh()->integrity_status);
        $this->assertSame(['files'], DB::table('backup_scope_goods')->pluck('scope')->all());
    }

    public function test_opaque_cpanel_archive_transport_does_not_prove_database_or_file_content_coverage(): void
    {
        [, , $run] = $this->backupGraph();
        [, $store] = $this->adapters();
        $source = new ScriptedCpanelBackupSource;
        app()->instance(CpanelBackupSource::class, $source);
        app(CpanelBackupFlow::class)->execute($run->id);
        $source->observedState = 'ready';
        app(CpanelBackupFlow::class)->execute($run->id, true);
        $this->travel(31)->seconds();
        app(CpanelBackupFlow::class)->execute($run->id, true);
        app(BackupVerification::class)->execute($run->id);
        $artifact = BackupArtifact::where('backup_run_id', $run->id)->sole();
        $this->assertSame('transport_verified', $artifact->verification_level);
        $this->assertSame(['full_account'], $artifact->coverage_scopes);
        $this->assertSame('partial', $run->fresh()->state);
        $this->assertSame('COVERAGE_GAP', $run->fresh()->reason_code);
        $this->assertSame(0, DB::table('backup_scope_goods')->count());
        $this->assertCount(1, $store->objects);
    }

    public function test_actor_disabled_during_read_is_authorization_unknown_not_corrupt_integrity_or_success(): void
    {
        [, $owner, $run] = $this->backupGraph('sftp');
        [, $store] = $this->adapters();
        app(SftpBackupFlow::class)->execute($run->id);
        $store->beforeRead = fn () => User::whereKey($owner->id)->update(['is_active' => false]);
        app(BackupVerification::class)->execute($run->id);
        $this->assertSame('reconcile_required', $run->fresh()->state);
        $this->assertSame('AUTHORIZATION_EXPIRED', $run->fresh()->reason_code);
        $this->assertSame('unknown', $run->fresh()->integrity_status);
        $this->assertSame('stored', BackupArtifact::where('backup_run_id', $run->id)->value('state'));
        $this->assertSame(0, DB::table('backup_scope_goods')->count());
    }
}
