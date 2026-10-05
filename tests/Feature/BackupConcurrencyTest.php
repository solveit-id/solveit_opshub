<?php

namespace Tests\Feature;

use App\Application\Backups\BackupRuns;
use App\Models\AssetUsage;
use App\Models\BackupRun;
use App\Models\BackupSourceOperation;
use App\Models\HostingAccount;
use App\Models\Project;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\BackupFixture;
use Tests\Support\RenewalFixture;
use Tests\Support\TelegramFixture;
use Tests\TestCase;

class BackupConcurrencyTest extends TestCase
{
    use BackupFixture, DatabaseMigrations, RenewalFixture, TelegramFixture;

    public function test_two_mysql_processes_enqueue_one_canonical_run_and_atomic_job(): void
    {
        [$org, , $run] = $this->backupGraph();
        $first = Project::forOrganization($org)->sole();
        for ($i = 2; $i <= 3; $i++) {
            $project = Project::create(['organization_id' => $org->id, 'client_id' => $first->client_id, 'code' => 'RACE'.$i, 'name' => 'Fictitious shared race '.$i, 'lifecycle' => 'active']);
            AssetUsage::create(['organization_id' => $org->id, 'asset_id' => HostingAccount::findOrFail($run->hosting_account_id)->asset_id, 'project_id' => $project->id, 'purpose' => 'hosting']);
        }
        $run->update(['state' => 'failed', 'reason_code' => 'FICTITIOUS_TERMINAL']);
        $before = DB::table('jobs')->count();
        $results = $this->race('enqueue', $run);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame(2, BackupRun::count());
        $this->assertSame($before + 1, DB::table('jobs')->count());
        $this->assertSame(1, BackupRun::whereNotNull('active_account_id')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'backup.run.requested')->where('object_id', $results[0])->count());
        $shared = BackupRun::findOrFail($results[0]);
        $this->assertCount(3, $shared->impacted_project_ids);
        app(BackupRuns::class)->preflight($shared->id);
        $triggers = $this->race('source', $shared);
        sort($triggers);
        $this->assertSame(['0', '1'], $triggers);
        $this->assertSame($shared->id, BackupSourceOperation::sole()->backup_run_id);
        $this->assertSame('awaiting_source', $shared->fresh()->state);
    }

    public function test_two_mysql_workers_issue_one_remote_intent_and_never_duplicate_source_trigger(): void
    {
        [, , $run] = $this->backupGraph();
        $results = $this->race('source', $run);
        sort($results);
        $this->assertSame(['0', '1'], $results);
        $this->assertSame('awaiting_source', $run->fresh()->state);
        $this->assertSame('accepted', $run->fresh()->source_status);
        $this->assertSame('not_started', $run->fresh()->transfer_status);
        $this->assertSame(1, BackupSourceOperation::count());
        $this->assertSame('accepted', BackupSourceOperation::sole()->state);
        $this->assertSame(1, DB::table('backup_run_attempts')->where('mode', 'source_request')->count());
        $this->assertSame(0, DB::table('backup_artifacts')->count());
    }

    private function race(string $operation, BackupRun $run): array
    {
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LIVE_CONNECTORS_ENABLED' => 'false', 'OPSHUB_PUBLIC_PROBES_ENABLED' => 'false'];
        $ready = (string) (microtime(true) + 2);
        $processes = [];
        for ($index = 0; $index < 2; $index++) {
            $process = new Process([PHP_BINARY, base_path('scripts/backup-race-worker.php'), $operation, (string) $run->id, $ready], base_path(), $environment, timeout: 30);
            $process->start();
            $processes[] = $process;
        }

        return array_map(function (Process $process): string {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

            return trim($process->getOutput());
        }, $processes);
    }
}
