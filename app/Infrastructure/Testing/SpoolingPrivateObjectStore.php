<?php

namespace App\Infrastructure\Testing;

use App\Infrastructure\Backup\PrivateObjectStore;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Models\BackupRun;
use Illuminate\Support\Str;

/** Private temp files model an independent provider in tests only, not a real failure domain. */
class SpoolingPrivateObjectStore implements PrivateObjectStore
{
    public array $objects = [];

    public bool $independent = true;

    public bool $private = true;

    public bool $transportProtected = true;

    public ?\Closure $afterCommit = null;

    public ?\Closure $beforeRead = null;

    public function __construct()
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Private object fixture is test-only.');
        }
    }

    public function fake(): bool
    {
        return true;
    }

    public function protection(BackupRun $run): array
    {
        return ['configured' => true, 'private' => $this->private, 'independent' => $this->independent, 'transport_protected' => $this->transportProtected];
    }

    public function put(BackupRun $run, string $reference, string $version, \Closure $producer): array
    {
        $id = $this->id($reference, $version);
        if (isset($this->objects[$id])) {
            throw new ConnectorFailure(ConnectorReason::ReconcileRequired);
        }
        $stream = tmpfile();
        if (! $stream) {
            throw new ConnectorFailure(ConnectorReason::Network);
        }
        $bytes = 0;
        try {
            $producer(function (string $chunk) use ($stream, $run, &$bytes): void {
                $bytes += strlen($chunk);
                if (strlen($chunk) > 65536 || $bytes > ($run->preflight_evidence['reserved_bytes'] ?? 0)) {
                    throw new ConnectorFailure(ConnectorReason::LimitExceeded);
                }
                if (fwrite($stream, $chunk) !== strlen($chunk)) {
                    throw new ConnectorFailure(ConnectorReason::Network);
                }
            });
            fflush($stream);
            $this->objects[$id] = $stream;
        } catch (\Throwable $error) {
            unset($this->objects[$id]);
            fclose($stream);
            throw $error;
        }
        if ($this->afterCommit) {
            ($this->afterCommit)($this, $reference, $version);
        }

        return $this->metadata($reference, $version);
    }

    public function metadata(string $reference, string $version): array
    {
        $stream = $this->objects[$this->id($reference, $version)] ?? throw new ConnectorFailure(ConnectorReason::ArtifactIncomplete);
        $path = stream_get_meta_data($stream)['uri'];

        return ['bytes' => fstat($stream)['size'], 'sha256' => hash_file('sha256', $path), 'private' => $this->private,
            'independent' => $this->independent, 'transport_protected' => $this->transportProtected, 'fake' => true, 'version' => $version];
    }

    public function read(string $reference, string $version)
    {
        if ($this->beforeRead) {
            ($this->beforeRead)();
        }
        $stream = $this->objects[$this->id($reference, $version)] ?? throw new ConnectorFailure(ConnectorReason::ArtifactIncomplete);
        $copy = fopen(stream_get_meta_data($stream)['uri'], 'rb');
        if (! $copy) {
            throw new ConnectorFailure(ConnectorReason::Network);
        }

        return $copy;
    }

    public function delete(string $reference, string $version): void
    {
        $id = $this->id($reference, $version);
        if (isset($this->objects[$id])) {
            fclose($this->objects[$id]);
            unset($this->objects[$id]);
        }
    }

    private function id(string $reference, string $version): string
    {
        if (! str_starts_with($reference, 'store:') || ! Str::isUuid(substr($reference, 6)) || ! Str::isUuid($version)) {
            throw new ConnectorFailure(ConnectorReason::PathBlocked);
        }

        return $reference.'/'.$version;
    }

    public function __destruct()
    {
        foreach ($this->objects as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
