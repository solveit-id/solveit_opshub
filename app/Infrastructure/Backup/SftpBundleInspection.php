<?php

namespace App\Infrastructure\Backup;

use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;

/** Incremental structural/checksum verification without extracting private payload to disk. */
class SftpBundleInspection
{
    private string $buffer = '';

    private string $phase = 'magic';

    private array $files = [];

    private array $entry = [];

    private array $seen = [];

    private int $remaining = 0;

    private int $total = 0;

    private $hash;

    private string $digest = '';

    public function __construct(private int $maximumBytes) {}

    public function accept(string $chunk): void
    {
        if (strlen($chunk) > 65536) {
            $this->fail();
        }
        $this->buffer .= $chunk;
        while (true) {
            if ($this->phase === 'end') {
                if ($this->buffer !== '') {
                    $this->fail();
                }

                return;
            }
            if ($this->phase === 'magic') {
                if (strlen($this->buffer) < strlen(SftpFileBundle::MAGIC)) {
                    return;
                }
                if ($this->take(strlen(SftpFileBundle::MAGIC)) !== SftpFileBundle::MAGIC) {
                    $this->fail();
                }
                $this->phase = 'header';
            } elseif ($this->phase === 'header') {
                if (strlen($this->buffer) < 4) {
                    return;
                }
                $length = unpack('N', substr($this->buffer, 0, 4))[1];
                if ($length < 1 || $length > 8192) {
                    $this->fail();
                }
                if (strlen($this->buffer) < 4 + $length) {
                    return;
                }
                $this->take(4);
                $entry = json_decode($this->take($length), true, 16, JSON_THROW_ON_ERROR);
                if (($entry['kind'] ?? null) === 'end') {
                    if (($entry['file_count'] ?? null) !== count($this->files)) {
                        $this->fail();
                    }
                    $this->phase = 'end';

                    continue;
                }
                $path = $entry['path'] ?? null;
                $root = $entry['root_index'] ?? null;
                if (($entry['kind'] ?? null) !== 'file' || ! is_string($path) || strlen($path) > 512 || $path === ''
                    || str_starts_with($path, '/') || str_ends_with($path, '/') || str_contains($path, '//')
                    || preg_match('/[\\\\\x00-\x1f\x7f%]/', $path) || array_intersect(explode('/', $path), ['.', '..'])
                    || ! is_int($root) || $root < 0 || $root > 19 || ! is_int($entry['bytes'] ?? null) || $entry['bytes'] < 0
                    || ! is_int($entry['mtime'] ?? null) || isset($this->seen[$root.':'.$path]) || count($this->files) >= 5000) {
                    $this->fail();
                }
                $this->seen[$root.':'.$path] = true;
                $this->entry = ['root_index' => $root, 'path' => $path, 'bytes' => $entry['bytes'], 'mtime' => $entry['mtime']];
                $this->remaining = $entry['bytes'];
                $this->total += $this->remaining;
                if ($this->total > $this->maximumBytes) {
                    $this->fail();
                }
                $this->hash = hash_init('sha256');
                $this->phase = 'body';
            } elseif ($this->phase === 'body') {
                if ($this->remaining > 0) {
                    if ($this->buffer === '') {
                        return;
                    }
                    $length = min($this->remaining, strlen($this->buffer));
                    hash_update($this->hash, $this->take($length));
                    $this->remaining -= $length;
                }
                if ($this->remaining === 0) {
                    $this->digest = hash_final($this->hash, true);
                    $this->phase = 'digest';
                }
            } elseif ($this->phase === 'digest') {
                if (strlen($this->buffer) < 32) {
                    return;
                }
                if (! hash_equals($this->digest, $this->take(32))) {
                    $this->fail();
                }
                $this->files[] = [...$this->entry, 'sha256' => bin2hex($this->digest), 'status' => 'read'];
                $this->phase = 'header';
            }
        }
    }

    public function finish(array $manifest): void
    {
        if ($this->phase !== 'end' || $this->buffer !== '' || ($manifest['errors'] ?? null) !== [] || ! is_array($manifest['files'] ?? null) || ! ManifestIdentity::equal($manifest['files'], $this->files)
            || ($manifest['file_count'] ?? null) !== count($this->files) || ($manifest['bytes'] ?? null) !== $this->total) {
            $this->fail();
        }
    }

    private function take(int $length): string
    {
        $value = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $value;
    }

    private function fail(): never
    {
        throw new ConnectorFailure(ConnectorReason::IntegrityFailed);
    }
}
