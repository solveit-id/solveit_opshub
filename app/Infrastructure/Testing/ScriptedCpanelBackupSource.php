<?php

namespace App\Infrastructure\Testing;

use App\Infrastructure\Backup\BackupWritePermit;
use App\Infrastructure\Backup\CpanelBackupSource;
use App\Infrastructure\Backup\SourceAcceptance;
use App\Infrastructure\Backup\SourceArtifact;
use App\Infrastructure\Backup\SourceObservation;
use App\Models\BackupRun;
use App\Models\Connector;

class ScriptedCpanelBackupSource implements CpanelBackupSource
{
    public int $requests = 0;

    public int $observations = 0;

    public int $streams = 0;

    public string $observedState = 'busy';

    public bool $completionProven = true;

    public bool $wrongAssociation = false;

    public int $size = 131073;

    public int $mtime;

    public ?SourceAcceptance $acceptance = null;

    public ?\Closure $duringStream = null;

    public function __construct()
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Source fixture is test-only.');
        }
        $this->mtime = now('UTC')->timestamp;
    }

    public function fake(): bool
    {
        return true;
    }

    public function configured(): bool
    {
        return true;
    }

    public function request(BackupRun $run, Connector $connector, BackupWritePermit $permit): SourceAcceptance
    {
        $this->requests++;

        return $this->acceptance ?? new SourceAcceptance('accepted', '123', null, true);
    }

    public function observe(BackupRun $run, Connector $connector, ?string $providerReference): SourceObservation
    {
        $this->observations++;

        return new SourceObservation($this->observedState, $this->observedState === 'ready'
            ? new SourceArtifact($this->wrongAssociation ? '00000000-0000-0000-0000-000000000000' : $run->run_reference, '123', 'fictitious-full-account.tar.gz', $this->size, $this->mtime, $this->completionProven, true)
            : null, fake: true);
    }

    public function stream(BackupRun $run, Connector $connector, SourceArtifact $artifact, \Closure $consume): array
    {
        $this->streams++;
        $hash = hash_init('sha256');
        for ($offset = 0; $offset < $artifact->bytes; $offset += 65536) {
            $chunk = str_repeat('x', min(65536, $artifact->bytes - $offset));
            hash_update($hash, $chunk);
            if ($this->duringStream) {
                ($this->duringStream)();
            }
            $consume($chunk);
        }

        return ['strategy' => 'cpanel_full_account', 'bytes' => $artifact->bytes, 'sha256' => hash_final($hash),
            'source_completion_proven' => true, 'reported_scopes' => ['files', 'database', 'full_account'], 'fake' => true];
    }
}
