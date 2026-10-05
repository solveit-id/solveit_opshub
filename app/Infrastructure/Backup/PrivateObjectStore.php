<?php

namespace App\Infrastructure\Backup;

use App\Models\BackupRun;

interface PrivateObjectStore
{
    public function fake(): bool;

    /** Explicit configuration/provenance; never infer independence from a different folder/bucket name. */
    public function protection(BackupRun $run): array;

    /** Write immutable, private version; abort/discard partial upload on producer failure. */
    public function put(BackupRun $run, string $reference, string $version, \Closure $producer): array;

    public function metadata(string $reference, string $version): array;

    /** Bounded reads through private stream, no public provider URL. */
    public function read(string $reference, string $version);

    public function delete(string $reference, string $version): void;
}
