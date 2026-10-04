<?php

namespace App\Infrastructure\Testing;

use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Infrastructure\Connectors\Sftp\SftpSession;
use App\Infrastructure\Connectors\Sftp\SftpSessionFactory;

/** Fictitious, bounded generated data; never a production fallback. */
class MemorySftpSessions implements SftpSession, SftpSessionFactory
{
    public int $authentications = 0;

    public int $closed = 0;

    public int $reads = 0;

    public int $largestRead = 0;

    public ?\Closure $afterRead = null;

    public ?ConnectorReason $failure = null;

    public bool $partial = false;

    public function __construct(public array $entries, public string $pin = 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA')
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('SFTP fake is test-only.');
        }
    }

    public function fake(): bool
    {
        return true;
    }

    public function open(ConnectorConfig $config): SftpSession
    {
        return $this;
    }

    public function fingerprint(): string
    {
        return $this->pin;
    }

    public function authenticate(): void
    {
        $this->authentications++;
        if ($this->failure) {
            throw new ConnectorFailure($this->failure);
        }
    }

    public function realpath(string $path): string
    {
        return $this->entries[$path]['canonical'] ?? $path;
    }

    public function lstat(string $path): array
    {
        return $this->entries[$path] ?? throw new ConnectorFailure(ConnectorReason::ArtifactIncomplete);
    }

    public function listing(string $path, int $limit): array
    {
        if (isset($this->entries[$path]['listing'])) {
            return $this->entries[$path]['listing'];
        }
        $result = [];
        foreach ($this->entries as $entryPath => $entry) {
            if (str_starts_with($entryPath, $path.'/') && ! str_contains(substr($entryPath, strlen($path) + 1), '/')) {
                $result[substr($entryPath, strlen($path) + 1)] = $entry;
                if (count($result) > $limit) {
                    throw new ConnectorFailure(ConnectorReason::LimitExceeded);
                }
            }
        }

        return $result;
    }

    public function read(string $path, int $offset, int $length): string
    {
        $this->reads++;
        $this->largestRead = max($this->largestRead, $length);
        $entry = $this->lstat($path);
        $data = isset($entry['content']) ? substr($entry['content'], $offset, $length) : str_repeat('x', min($length, max(0, $entry['size'] - $offset)));
        if ($this->afterRead) {
            ($this->afterRead)($this, $path);
        }

        return $this->partial ? substr($data, 0, max(0, strlen($data) - 1)) : $data;
    }

    public function close(): void
    {
        $this->closed++;
    }
}
