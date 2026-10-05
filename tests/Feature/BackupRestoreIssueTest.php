<?php

namespace Tests\Feature;

use App\Application\Backups\BackupIssues;
use App\Application\Backups\BackupPolicies;
use App\Application\Backups\BackupRuns;
use App\Application\Backups\BackupVerification;
use App\Application\Backups\CpanelBackupFlow;
use App\Application\Backups\RestoreDrills;
use App\Application\Backups\SftpBackupFlow;
use App\Application\TelegramNotifications\DeliveryMaterializer;
use App\Application\TelegramNotifications\DeliverySender;
use App\Application\TelegramNotifications\OutboxWriter;
use App\Application\TelegramNotifications\TelegramDigest;
use App\Domain\IdentityAccess\Role;
use App\Infrastructure\Backup\BackupKeyResolver;
use App\Infrastructure\Backup\CpanelBackupSource;
use App\Infrastructure\Backup\PrivateObjectStore;
use App\Infrastructure\Backup\RestoreTarget;
use App\Infrastructure\Backup\TransferClock;
use App\Infrastructure\Testing\AdvancingTransferClock;
use App\Infrastructure\Testing\IsolatedFileRestoreTarget;
use App\Infrastructure\Testing\RandomBackupKeys;
use App\Infrastructure\Testing\ScriptedCpanelBackupSource;
use App\Infrastructure\Testing\SpoolingPrivateObjectStore;
use App\Models\BackupArtifact;
use App\Models\BackupInternalIncident;
use App\Models\BackupPolicy;
use App\Models\BackupSourceOperation;
use App\Models\Membership;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\TelegramBot;
use App\Models\TelegramDelivery;
use App\Models\TelegramDestination;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BackupFixture;
use Tests\Support\RenewalFixture;
use Tests\Support\TelegramFixture;
use Tests\Support\VerifiedBackupFixture;
use Tests\TestCase;

class BackupRestoreIssueTest extends TestCase
{
    use BackupFixture, RefreshDatabase, RenewalFixture, TelegramFixture, VerifiedBackupFixture;

    private function target(): IsolatedFileRestoreTarget
    {
        $target = new IsolatedFileRestoreTarget;
        app()->instance(RestoreTarget::class, $target);

        return $target;
    }

    public function test_real_isolated_fixture_extraction_records_scoped_restore_checks_time_and_database_gap(): void
    {
        [$org, $owner, $run, , , $artifact] = $this->verifiedBackup();
        $target = $this->target();
        $drills = app(RestoreDrills::class);
        $drill = $drills->enqueue($org, $owner, $artifact, 'isolated:local-fixture', 'isolated');
        $this->assertTrue($artifact->fresh()->restore_pending);
        $drills->execute($drill->id);
        $drills->execute($drill->id); // Duplicate job cannot re-extract.
        $drill->refresh();
        $this->assertSame('passed', $drill->state);
        $this->assertSame('restore_verified', $artifact->fresh()->verification_level);
        $this->assertSame('partial', $run->fresh()->state);
        $this->assertSame('COVERAGE_GAP', $run->fresh()->reason_code);
        $this->assertSame(['files'], DB::table('backup_scope_goods')->pluck('scope')->all());
        $this->assertTrue($drill->checks['file_hashes_match']);
        $this->assertFalse($drill->checks['database_restored']);
        $this->assertFalse($drill->checks['application_health_proven']);
        $this->assertNotNull($drill->completed_at);
        $this->assertLessThanOrEqual(65536, $target->maximumChunk);
        $this->assertFalse($artifact->fresh()->restore_pending);
        $this->assertDirectoryDoesNotExist(storage_path('framework/testing/restore-'.$drill->workspace_reference));
        $this->getJson('/api/v1/organizations/'.$org->id.'/backups')->assertOk()->assertJsonPath('data.items.0.runs.0.restore_drills.0.state', 'passed');
        $this->assertArrayNotHasKey('workspace_reference', $drill->toArray());
        $this->assertArrayNotHasKey('target_reference', $drill->toArray());
    }

    public function test_restore_required_files_only_policy_succeeds_only_after_actual_file_checks(): void
    {
        [$org, $owner, , $connector, $policy] = $this->verifiedBackup();
        $this->travel(2)->seconds(); // New capture must be newer than the prior last-good in fast CI.
        $this->target();
        $policy = app(BackupPolicies::class)->configure($org, $owner, $connector, [...$policy->configuration, 'required_scopes' => ['files'], 'verification' => 'restore_verified'], true, 1);
        $run = app(BackupRuns::class)->enqueue($org, $owner, $policy, 2, 'restore-required-fixture');
        app(BackupRuns::class)->preflight($run->id);
        app(SftpBackupFlow::class)->execute($run->id);
        app(BackupVerification::class)->execute($run->id);
        $this->assertSame('VERIFICATION_LEVEL_REQUIRED', $run->fresh()->reason_code);
        $artifact = BackupArtifact::where('backup_run_id', $run->id)->sole();
        $drill = app(RestoreDrills::class)->enqueue($org, $owner, $artifact, 'isolated:local-fixture', 'isolated');
        app(RestoreDrills::class)->execute($drill->id);
        $this->assertSame('succeeded', $run->fresh()->state);
        $this->assertSame($artifact->id, DB::table('backup_scope_goods')->value('backup_artifact_id'));
    }

    public function test_production_target_step_up_missing_permission_and_kill_switch_are_denied(): void
    {
        [$org, $owner, , , , $artifact] = $this->verifiedBackup();
        $target = $this->target();
        $url = '/api/v1/organizations/'.$org->id.'/backup-artifacts/'.$artifact->id.'/restore-drills';
        $this->postJson($url, ['target_kind' => 'production', 'target_reference' => 'isolated:local-fixture'])->assertUnprocessable();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp])->postJson($url, ['target_kind' => 'isolated', 'target_reference' => 'isolated:local-fixture'])->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $target->production = true;
        $drill = app(RestoreDrills::class)->enqueue($org, $owner, $artifact, 'isolated:local-fixture', 'isolated');
        app(RestoreDrills::class)->execute($drill->id);
        $this->assertSame('unknown', $drill->fresh()->state);
        $this->assertSame('content_verified', $artifact->fresh()->verification_level);
        $this->assertTrue($artifact->fresh()->restore_pending);
        $target->production = false;
        $this->postJson('/api/v1/organizations/'.$org->id.'/backup-restore-drills/'.$drill->id.'/reconcile', ['version' => 1])->assertOk();
        app(BackupPolicies::class)->setPaused($org, $owner, true, 1);
        $this->postJson($url, ['target_kind' => 'isolated', 'target_reference' => 'isolated:local-fixture'])->assertConflict();
        $operator = User::factory()->create();
        Membership::create(['organization_id' => $org->id, 'user_id' => $operator->id, 'role' => Role::Operator, 'is_active' => true]);
        $project = Project::forOrganization($org)->sole();
        DB::table('project_accesses')->insert(['organization_id' => $org->id, 'project_id' => $project->id, 'user_id' => $operator->id]);
        $this->actingAs($operator)->postJson($url, ['target_kind' => 'isolated', 'target_reference' => 'isolated:local-fixture'])->assertForbidden();
    }

    public function test_tamper_and_mid_restore_authorization_revocation_never_promote_and_workspace_is_discarded(): void
    {
        [$org, $owner, , , , $artifact, $store] = $this->verifiedBackup();
        $this->target();
        $drills = app(RestoreDrills::class);
        $drill = $drills->enqueue($org, $owner, $artifact, 'isolated:local-fixture', 'isolated');
        $stream = $store->objects[$artifact->object_reference.'/'.$artifact->object_version];
        fseek($stream, 100);
        $value = fread($stream, 1);
        fseek($stream, 100);
        fwrite($stream, chr(ord($value) ^ 1));
        fflush($stream);
        $drills->execute($drill->id);
        $this->assertSame('failed', $drill->fresh()->state);
        $this->assertSame('content_verified', $artifact->fresh()->verification_level);
        $this->assertFalse($artifact->fresh()->restore_pending);
        $this->assertSame('failed', $artifact->fresh()->state);
        $policy = BackupPolicy::where('hosting_account_id', $artifact->hosting_account_id)->sole();
        $run = app(BackupRuns::class)->enqueue($org, $owner, $policy, 1, 'restore-revoke-next-fixture');
        app(BackupRuns::class)->preflight($run->id);
        app(SftpBackupFlow::class)->execute($run->id);
        app(BackupVerification::class)->execute($run->id);
        $artifact = BackupArtifact::where('backup_run_id', $run->id)->sole();
        $second = $drills->enqueue($org, $owner, $artifact, 'isolated:local-fixture', 'isolated');
        $store->beforeRead = fn () => User::whereKey($owner->id)->update(['is_active' => false]);
        $drills->execute($second->id);
        $this->assertSame('unknown', $second->fresh()->state);
        $this->assertSame('AUTHORIZATION_EXPIRED', $second->fresh()->reason_code);
        $this->assertSame('content_verified', $artifact->fresh()->verification_level);
        $this->assertDirectoryDoesNotExist(storage_path('framework/testing/restore-'.$second->workspace_reference));
    }

    public function test_crash_workspace_reconcile_discards_only_owned_isolated_tree_and_never_claims_restore_success(): void
    {
        [$org, $owner, , , , $artifact] = $this->verifiedBackup();
        $target = $this->target();
        $drills = app(RestoreDrills::class);
        $drill = $drills->enqueue($org, $owner, $artifact, 'isolated:local-fixture', 'isolated');
        $drill->update(['state' => 'running', 'lease_owner' => (string) Str::uuid(), 'leased_until' => now('UTC')->subSecond()]);
        $target->begin($drill);
        $target->entry(['root_index' => 0, 'path' => 'fictitious.txt']);
        $target->write('fictitious data');
        $drills->reconcile($org, $owner, $drill, 1);
        $this->assertSame('failed', $drill->fresh()->state);
        $this->assertSame('DRILL_INTERRUPTED', $drill->fresh()->reason_code);
        $this->assertFalse($artifact->fresh()->restore_pending);
        $this->assertSame('content_verified', $artifact->fresh()->verification_level);
        $this->assertDirectoryDoesNotExist(storage_path('framework/testing/restore-'.$drill->workspace_reference));
    }

    public function test_cpanel_restore_uses_manual_runbook_record_without_universal_or_verified_promotion(): void
    {
        [$org, $owner, $old, $connector, $policy] = $this->verifiedBackup();
        $connector->update(['kind' => 'cpanel']);
        // Fictitious opaque archive fixture; preserves tested full-account limitation.
        $artifact = BackupArtifact::where('backup_run_id', $old->id)->sole();
        $artifact->update(['source_kind' => 'cpanel', 'verification_level' => 'transport_verified', 'coverage_scopes' => ['full_account']]);
        $drill = app(RestoreDrills::class)->enqueue($org, $owner, $artifact, 'isolated:provider-fixture', 'isolated');
        $this->assertSame('manual_required', $drill->state);
        $this->assertSame('cpanel-provider-manual-v1', $drill->runbook);
        $this->assertTrue($artifact->fresh()->restore_pending);
        $this->postJson('/api/v1/organizations/'.$org->id.'/backup-restore-drills/'.$drill->id.'/manual-record', ['version' => 1, 'evidence_reference' => 'evidence:provider-fixture', 'checks' => ['target_isolated' => true, 'production_overwrite' => false, 'files_passed' => true, 'database_passed' => false]])->assertOk()->assertJsonPath('data.state', 'manual_recorded');
        $this->assertSame('transport_verified', $artifact->fresh()->verification_level);
        $this->assertFalse($artifact->fresh()->restore_pending);
    }

    public function test_failure_overdue_incident_and_recovery_task_are_idempotent_internal_scoped_and_last_good_stays_dated(): void
    {
        [$org, $owner, $run, $connector, $policy, $artifact] = $this->verifiedBackup();
        $issues = app(BackupIssues::class);
        $before = (array) DB::table('backup_scope_goods')->sole();
        $this->assertSame(1, $issues->tick()); // Partial DB gap creates internal incident/task immediately.
        $this->assertSame(0, $issues->tick());
        $this->travel(31)->hours();
        app(BackupPolicies::class)->configure($org, $owner, $connector, $policy->configuration, true, 1); // Retention approval cannot reset missing-scope RPO age.
        $this->assertSame(2, $issues->tick()); // Files and DB each exceed required RPO.
        $this->assertSame(0, $issues->tick());
        $this->assertSame(3, BackupInternalIncident::count());
        $this->assertSame(3, DB::table('backup_recovery_tasks')->count());
        $this->assertSame(0, DB::table('incidents')->count());
        $this->assertSame($before, (array) DB::table('backup_scope_goods')->sole());
        app(DeliveryMaterializer::class)->materialize($org);
        $this->assertGreaterThan(0, TelegramDelivery::count());
        $text = TelegramDelivery::pluck('text')->implode(' ');
        $this->assertStringContainsString('/backups', $text);
        foreach ([$artifact->key_reference, $artifact->object_reference, 'download/', 'index.txt'] as $private) {
            $this->assertStringNotContainsString($private, $text);
        }
        $overdue = BackupInternalIncident::where('kind', 'overdue')->firstOrFail();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson('/api/v1/organizations/'.$org->id.'/backup-issues/'.$overdue->id.'/resolve', ['version' => 1, 'evidence_reference' => 'evidence:review'])->assertConflict();
        $failure = BackupInternalIncident::where('kind', 'failure')->sole();
        $this->postJson('/api/v1/organizations/'.$org->id.'/backup-issues/'.$failure->id.'/resolve', ['version' => 1, 'evidence_reference' => 'evidence:review'])->assertOk();
        $this->assertSame('resolved', DB::table('backup_recovery_tasks')->where('backup_internal_incident_id', $failure->id)->value('state'));
    }

    public function test_cpanel_source_timestamp_is_conservative_request_start_not_late_retrieval_time(): void
    {
        [$org, $owner, $run, $connector, $policy] = $this->backupGraph();
        // Bind encrypted sink dependencies using the same tested fixture adapters without another graph.
        $store = new SpoolingPrivateObjectStore;
        app()->instance(PrivateObjectStore::class, $store);
        app()->instance(BackupKeyResolver::class, new RandomBackupKeys);
        app()->instance(TransferClock::class, new AdvancingTransferClock);
        $source = new ScriptedCpanelBackupSource;
        app()->instance(CpanelBackupSource::class, $source);
        app(CpanelBackupFlow::class)->execute($run->id);
        $origin = BackupSourceOperation::where('backup_run_id', $run->id)->sole()->started_at;
        $source->observedState = 'ready';
        $this->travel(31)->seconds();
        app(CpanelBackupFlow::class)->execute($run->id, true);
        $this->travel(31)->seconds();
        app(CpanelBackupFlow::class)->execute($run->id, true);
        $artifact = BackupArtifact::where('backup_run_id', $run->id)->sole();
        $this->assertSame($origin->toIso8601String(), $artifact->source_observed_at->toIso8601String());
        $this->assertSame($origin->toIso8601String(), $artifact->manifest['capture_origin_utc']);
    }

    public function test_resolved_backup_issue_is_superseded_before_provider_delivery_and_digest_uses_current_state(): void
    {
        [$org, $owner] = $this->verifiedBackup();
        $issues = app(BackupIssues::class);
        $issues->tick();
        app(DeliveryMaterializer::class)->materialize($org);
        $issue = BackupInternalIncident::sole();
        $delivery = TelegramDelivery::whereIn('outbox_event_id', OutboxEvent::where('aggregate_type', 'backup_internal_incident')->select('id'))->firstOrFail();
        $issues->resolve($org, $owner, $issue, 1, 'evidence:reviewed-gap');
        app(DeliverySender::class)->send($delivery->id);
        $this->assertSame('superseded', $delivery->fresh()->state);
        $this->assertSame('BACKUP_ISSUE_RESOLVED', $delivery->fresh()->result_code);
        $this->assertNull($delivery->fresh()->message_id);
        $bot = TelegramBot::forOrganization($org)->sole();
        $dest = TelegramDestination::forOrganization($org)->sole();
        $event = app(OutboxWriter::class)->record($org, 'telegram.digest', 'telegram_destination_digest', $dest->id, 1, ['local_date' => now()->toDateString()]);
        $result = app(TelegramDigest::class)->current($org, $bot, $dest, $event, [$delivery->outbox_event_id], CarbonImmutable::now('UTC'));
        $text = implode(' ', $result['parts']);
        $this->assertStringContainsString('CURRENT resolved', $text);
        $this->assertStringContainsString('Backup internal open: 0', $text);
    }
}
