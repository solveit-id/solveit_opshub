<?php

namespace Tests\Feature;

use App\Application\Backups\BackupArtifactAccess;
use App\Application\Backups\BackupPolicies;
use App\Application\Backups\BackupRetention;
use App\Application\Backups\BackupRuns;
use App\Application\Backups\BackupScheduler;
use App\Application\Backups\BackupVerification;
use App\Application\Backups\SftpBackupFlow;
use App\Domain\IdentityAccess\Role;
use App\Infrastructure\Backup\TemporarySourceCleaner;
use App\Models\AssetUsage;
use App\Models\BackupArtifact;
use App\Models\BackupRun;
use App\Models\HostingAccount;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BackupFixture;
use Tests\Support\RenewalFixture;
use Tests\Support\TelegramFixture;
use Tests\Support\VerifiedBackupFixture;
use Tests\TestCase;

class BackupAccessRetentionTest extends TestCase
{
    use BackupFixture, RefreshDatabase, RenewalFixture, TelegramFixture, VerifiedBackupFixture;

    public function test_scoped_step_up_expiring_user_bound_encrypted_download_and_audit(): void
    {
        [$org, $owner, $run, , , $artifact, $store] = $this->verifiedBackup();
        $base = '/api/v1/organizations/'.$org->id.'/backup-artifacts/'.$artifact->id;
        $this->getJson('/api/v1/organizations/'.$org->id.'/backups')->assertOk()->assertJsonPath('data.items.0.last_goods.0.source_observed_at', $artifact->source_observed_at->toIso8601String());
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp])->postJson($base.'/download-link')->assertForbidden();
        $link = $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson($base.'/download-link')->assertCreated()->json('data');
        $response = $this->get($link['url'])->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $raw = $store->read($artifact->object_reference, $artifact->object_version);
        $ciphertext = stream_get_contents($raw);
        fclose($raw);
        $this->assertSame($ciphertext, $response->streamedContent());
        $this->assertStringNotContainsString('index.txt', $ciphertext);
        $token = basename($link['url']);
        $this->assertNotSame($token, DB::table('backup_download_links')->value('token_digest'));
        $other = User::factory()->create();
        Membership::create(['organization_id' => $org->id, 'user_id' => $other->id, 'role' => Role::Owner, 'is_active' => true]);
        $this->actingAs($other)->get($link['url'])->assertNotFound();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->travel(301)->seconds();
        $this->get($link['url'])->assertNotFound();
        $this->assertTrue(DB::table('audit_events')->where('action', 'backup.download.finished')->exists());
        $this->assertStringNotContainsString($token, DB::table('outbox_events')->get()->toJson());
        $this->assertSame('partial', $run->fresh()->state);
    }

    public function test_shared_account_requires_all_current_and_historical_projects_and_explicit_download_grant(): void
    {
        [$org, $owner, $run, $connector, , $artifact] = $this->verifiedBackup();
        $operator = User::factory()->create();
        $member = Membership::create(['organization_id' => $org->id, 'user_id' => $operator->id, 'role' => Role::Operator, 'extra_permissions' => ['backup.download'], 'is_active' => true]);
        $first = Project::forOrganization($org)->sole();
        $ids = [$first->id];
        for ($i = 2; $i <= 3; $i++) {
            $project = Project::create(['organization_id' => $org->id, 'client_id' => $first->client_id, 'code' => 'SHARED'.$i, 'name' => 'Fictitious shared '.$i, 'lifecycle' => 'active']);
            $usage = AssetUsage::create(['organization_id' => $org->id, 'asset_id' => HostingAccount::findOrFail($connector->hosting_account_id)->asset_id, 'project_id' => $project->id, 'purpose' => 'hosting']);
            $ids[] = $project->id;
        }
        $run->update(['impacted_project_ids' => $ids]);
        DB::table('project_accesses')->insert(['organization_id' => $org->id, 'project_id' => $first->id, 'user_id' => $operator->id]);
        $url = '/api/v1/organizations/'.$org->id;
        $this->actingAs($operator)->getJson($url.'/backups')->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson($url.'/backup-artifacts/'.$artifact->id.'/download-link')->assertNotFound();
        foreach (array_slice($ids, 1) as $id) {
            DB::table('project_accesses')->insert(['organization_id' => $org->id, 'project_id' => $id, 'user_id' => $operator->id]);
        }
        $link = $this->postJson($url.'/backup-artifacts/'.$artifact->id.'/download-link')->assertCreated()->json('data.url');
        $member->update(['extra_permissions' => []]);
        $this->get($link)->assertForbidden();
        $member->update(['extra_permissions' => ['backup.download']]);
        $usage->delete(); // Removing current usage never removes historical scope.
        DB::table('project_accesses')->where('project_id', end($ids))->delete();
        $this->get($link)->assertNotFound();
        $this->getJson($url.'/backups')->assertJsonCount(0, 'data.items.0.runs');
    }

    public function test_retention_tiers_no_duplicates_protect_last_good_hold_restore_links_and_recheck(): void
    {
        [$org, $owner, $run, , $policy, $artifact] = $this->verifiedBackup();
        $old = $artifact->source_observed_at;
        $extra = [];
        for ($i = 1; $i <= 12; $i++) {
            $copyRun = $run->replicate(['active_account_id']);
            $copyRun->fill(['run_reference' => (string) Str::uuid(), 'idempotency_key' => 'retention-fixture-'.$i])->save();
            $copy = $artifact->replicate();
            $copy->fill(['backup_run_id' => $copyRun->id, 'artifact_reference' => (string) Str::uuid(), 'object_reference' => 'store:'.Str::uuid(), 'object_version' => (string) Str::uuid(), 'source_observed_at' => $old->subMonths($i + 4)])->save();
            $extra[] = $copy;
        }
        $extra[0]->update(['legal_hold' => true]);
        $extra[1]->update(['restore_pending' => true]);
        app(BackupArtifactAccess::class)->issue($org, $owner, $extra[2]);
        $retention = app(BackupRetention::class);
        $report = $retention->dryRun($org, $owner, $policy);
        $decisions = collect($report->decisions)->keyBy('artifact_id');
        $this->assertContains('last_known_good', $decisions[$artifact->id]['reasons']);
        $this->assertContains('legal_hold', $decisions[$extra[0]->id]['reasons']);
        $this->assertContains('restore_pending', $decisions[$extra[1]->id]['reasons']);
        $this->assertContains('active_download', $decisions[$extra[2]->id]['reasons']);
        $this->assertCount(count($report->decisions), $decisions);
        $candidate = collect($report->decisions)->firstWhere('decision', 'delete');
        $this->assertNotNull($candidate);
        $retention->enqueue($org, $owner, $report);
        app(BackupArtifactAccess::class)->hold($org, $owner, BackupArtifact::findOrFail($candidate['artifact_id']), $candidate['version'], true);
        app(BackupPolicies::class)->setPaused($org, $owner, true, 1);
        $retention->apply($report->id);
        $this->assertSame(0, BackupArtifact::where('state', 'deleted')->count());
        $this->assertSame('completed', $report->fresh()->state);
        $this->postJson('/api/v1/organizations/'.$org->id.'/backup-artifacts/'.$artifact->id.'/hold', ['version' => 99, 'legal_hold' => true])->assertConflict();
    }

    public function test_deletion_crash_read_reconcile_and_unverified_never_deleted(): void
    {
        [$org, $owner, , , $policy, $artifact, $store] = $this->verifiedBackup();
        $artifact->update(['state' => 'delete_unknown', 'version' => 2]);
        $retention = app(BackupRetention::class);
        $this->assertSame('verified', $retention->reconcile($org, $owner, $artifact, 2)->state);
        $artifact->refresh()->update(['state' => 'deleting', 'version' => 4]);
        $store->delete($artifact->object_reference, $artifact->object_version);
        $this->assertSame('deleted', $retention->reconcile($org, $owner, $artifact, 4)->state);
        $this->assertNotNull($artifact->fresh()->deleted_at);
        $artifact->refresh()->update(['deleted_at' => null, 'state' => 'stored']);
        $report = $retention->dryRun($org, $owner, $policy);
        $this->assertContains('unverified_or_uncertain', $report->decisions[0]['reasons']);
        $this->travel(31)->minutes();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson('/api/v1/organizations/'.$org->id.'/backup-retention-reports/'.$report->id.'/apply')->assertConflict();
    }

    public function test_approved_cleanup_deletes_only_expired_private_version_once(): void
    {
        [$org, $owner, $oldRun, $connector, $policy, $oldArtifact, $store] = $this->verifiedBackup();
        $oldArtifact->update(['source_observed_at' => now('UTC')->subYear()]);
        $policy = app(BackupPolicies::class)->configure($org, $owner, $connector, [...$policy->configuration, 'retention' => ['daily' => 1, 'weekly' => 1, 'monthly' => 1]], true, 1);
        $next = app(BackupRuns::class)->enqueue($org, $owner, $policy, 2, 'retention-next-good');
        app(BackupRuns::class)->preflight($next->id);
        app(SftpBackupFlow::class)->execute($next->id);
        app(BackupVerification::class)->execute($next->id);
        $new = BackupArtifact::where('backup_run_id', $next->id)->sole();
        $retention = app(BackupRetention::class);
        $report = $retention->dryRun($org, $owner, $policy);
        $retention->enqueue($org, $owner, $report);
        $retention->apply($report->id);
        $retention->apply($report->id); // Duplicate queue job has no second delete effect.
        $this->assertSame('deleted', $oldArtifact->fresh()->state);
        $this->assertSame('missing', $store->presence($oldArtifact->object_reference, $oldArtifact->object_version));
        $this->assertSame('present', $store->presence($new->object_reference, $new->object_version));
        $this->assertSame($new->id, DB::table('backup_scope_goods')->value('backup_artifact_id'));
        $this->assertCount(1, $report->fresh()->results);
        $this->assertSame($oldArtifact->id, $report->fresh()->results[0]['artifact_id']);
        $this->assertSame('deleted', $report->fresh()->results[0]['state']);
    }

    public function test_scheduler_local_slot_coalesces_missed_days_and_retention_once_per_approved_day(): void
    {
        [$org, , $run, , $policy] = $this->verifiedBackup();
        $this->travel(3)->days();
        $scheduler = app(BackupScheduler::class);
        $this->assertSame(1, $scheduler->tick());
        $this->assertSame(0, $scheduler->tick());
        $this->assertSame(1, DB::table('backup_schedule_slots')->count());
        $this->assertSame(2, BackupRun::count());
        $this->assertSame(1, $scheduler->retention());
        $this->assertSame(0, $scheduler->retention());
        $this->assertSame(1, DB::table('backup_retention_reports')->count());
        $this->getJson('/api/v1/organizations/'.$org->id.'/backups')->assertOk()->assertJsonPath('data.items.0.retention', ['daily' => 7, 'weekly' => 4, 'monthly' => 3]);
    }

    public function test_source_cleanup_requires_verified_own_cpanel_proof_and_never_sftp_write(): void
    {
        [$org, $owner, $run, $connector, $policy, $artifact] = $this->verifiedBackup();
        $settings = [...$policy->configuration, 'cleanup_temporary_source' => true];
        app(BackupPolicies::class)->configure($org, $owner, $connector, $settings, true, 1);
        $cleaner = new class implements TemporarySourceCleaner
        {
            public bool $owned = false;

            public int $deletes = 0;

            public function fake(): bool
            {
                return true;
            }

            public function owned(BackupRun $run, BackupArtifact $artifact): bool
            {
                return $this->owned;
            }

            public function deleteOwned(BackupRun $run, BackupArtifact $artifact): void
            {
                if (! $this->owned) {
                    throw new \LogicException;
                } $this->deletes++;
            }
        };
        app()->instance(TemporarySourceCleaner::class, $cleaner);
        $url = '/api/v1/organizations/'.$org->id.'/backup-artifacts/'.$artifact->id.'/cleanup-source';
        $cleaner->owned = true;
        $this->postJson($url)->assertConflict();
        $connector->update(['kind' => 'cpanel']); // Explicit fictitious ownership contract, no provider I/O.
        $run->update(['source_status' => 'retrieved']);
        $cleaner->owned = false;
        $this->postJson($url)->assertConflict();
        $this->assertSame(0, $cleaner->deletes);
        $cleaner->owned = true;
        $this->postJson($url)->assertOk()->assertJsonPath('data.state', 'deleted');
        $this->assertSame(1, $cleaner->deletes);
        $this->postJson($url)->assertConflict();
        $this->assertSame(1, $cleaner->deletes);
    }
}
