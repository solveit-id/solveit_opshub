<?php

namespace App\Infrastructure\Testing;

use App\Infrastructure\Backup\BackupSink;
use App\Infrastructure\Backup\TransferReceipt;
use App\Models\BackupRun;
use Illuminate\Support\Str;

/** Ephemeral private fake sink. No encryption, independent-provider or restore claim. */
class SpoolingBackupSink implements BackupSink
{
    public int $bytes = 0;

    public array $manifest = [];

    public int $largestChunk = 0;

    public function __construct()
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Sink fixture is test-only.');
        }
    }

    public function fake(): bool
    {
        return true;
    }

    public function available(): bool
    {
        return true;
    }

    public function receive(BackupRun $run, \Closure $producer): TransferReceipt
    {
        $file = fopen('php://temp/maxmemory:1048576', 'w+b');
        $this->bytes = 0;
        $hash = hash_init('sha256');
        try {
            $this->manifest = $producer(function (string $chunk) use ($file, $hash): void {
                if (strlen($chunk) > 65536) {
                    throw new \LogicException('Unbounded fixture transfer.');
                }
                $this->largestChunk = max($this->largestChunk, strlen($chunk));
                if (fwrite($file, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Private sink write failed.');
                }
                $this->bytes += strlen($chunk);
                hash_update($hash, $chunk);
            });

            return new TransferReceipt('store:'.Str::uuid(), (string) Str::uuid(), $this->bytes, hash_final($hash), true, true);
        } finally {
            fclose($file);
        }
    }
}
