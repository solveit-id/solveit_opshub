<?php

use App\Application\Backups\BackupRuns;
use App\Application\Backups\CpanelBackupFlow;
use App\Infrastructure\Backup\BackupKeyResolver;
use App\Infrastructure\Backup\BackupWritePermit;
use App\Infrastructure\Backup\CpanelBackupSource;
use App\Infrastructure\Backup\PrivateObjectStore;
use App\Infrastructure\Backup\SourceAcceptance;
use App\Infrastructure\Testing\RandomBackupKeys;
use App\Infrastructure\Testing\ScriptedCpanelBackupSource;
use App\Infrastructure\Testing\SpoolingPrivateObjectStore;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Models\Connector;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'mysql' || ! str_ends_with(config('database.connections.mysql.database'), '_test') || config('database.connections.mysql.url')
    || config('opshub.live_connectors_enabled') || config('opshub.public_probes_enabled')) {
    fwrite(STDERR, "Refusing non-isolated test environment.\n");
    exit(1);
}
[$script, $operation, $id, $readyAt] = $argv;
while (microtime(true) < (float) $readyAt) {
    usleep(1000);
}
$run = BackupRun::findOrFail((int) $id);
if ($operation === 'enqueue') {
    $policy = BackupPolicy::findOrFail($run->backup_policy_id);
    echo app(BackupRuns::class)->enqueue(Organization::findOrFail($run->organization_id), User::findOrFail($run->actor_user_id), $policy, $policy->version, 'two-process-same-request')->id;
} elseif ($operation === 'source') {
    // Deliberate overlap in a test-only source. No provider DNS/HTTP/SFTP is performed.
    $source = new class extends ScriptedCpanelBackupSource
    {
        public function request(BackupRun $run, Connector $connector, BackupWritePermit $permit): SourceAcceptance
        {
            usleep(750000);

            return parent::request($run, $connector, $permit);
        }
    };
    app()->instance(CpanelBackupSource::class, $source);
    app()->instance(PrivateObjectStore::class, new SpoolingPrivateObjectStore);
    app()->instance(BackupKeyResolver::class, new RandomBackupKeys);
    app(CpanelBackupFlow::class)->execute($run->id);
    echo $source->requests;
} else {
    fwrite(STDERR, "Unsupported race operation.\n");
    exit(1);
}
