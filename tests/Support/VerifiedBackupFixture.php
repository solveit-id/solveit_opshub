<?php

namespace Tests\Support;

use App\Application\Backups\BackupVerification;
use App\Application\Backups\SftpBackupFlow;
use App\Infrastructure\Backup\BackupKeyResolver;
use App\Infrastructure\Backup\PrivateObjectStore;
use App\Infrastructure\Backup\TransferClock;
use App\Infrastructure\Connectors\Sftp\SftpSessionFactory;
use App\Infrastructure\Testing\AdvancingTransferClock;
use App\Infrastructure\Testing\MemorySftpSessions;
use App\Infrastructure\Testing\RandomBackupKeys;
use App\Infrastructure\Testing\SpoolingPrivateObjectStore;
use App\Models\BackupArtifact;

trait VerifiedBackupFixture
{
    protected function verifiedBackup(): array
    {
        [$org, $owner, $run, $connector, $policy] = $this->backupGraph('sftp');
        $store = new SpoolingPrivateObjectStore;
        $keys = new RandomBackupKeys;
        app()->instance(PrivateObjectStore::class, $store);
        app()->instance(BackupKeyResolver::class, $keys);
        app()->instance(TransferClock::class, new AdvancingTransferClock);
        app()->instance(SftpSessionFactory::class, new MemorySftpSessions(['/srv/app' => ['type' => 2, 'mtime' => 100], '/srv/app/index.txt' => ['type' => 1, 'mtime' => 101, 'size' => 4096]]));
        app(SftpBackupFlow::class)->execute($run->id);
        app(BackupVerification::class)->execute($run->id);

        return [$org, $owner, $run->fresh(), $connector, $policy, BackupArtifact::where('backup_run_id', $run->id)->sole(), $store, $keys];
    }
}
