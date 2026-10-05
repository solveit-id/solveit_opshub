<?php

namespace App\Infrastructure\Testing;

use App\Infrastructure\Backup\ManifestIdentity;
use App\Infrastructure\Backup\RestoreTarget;
use App\Models\BackupRestoreDrill;
use Illuminate\Support\Str;

/** Real extraction of fictitious files, exclusively inside a newly generated private disposable test directory. */
class IsolatedFileRestoreTarget implements RestoreTarget
{
    private ?string $root = null;

    private array $paths = [];

    private array $files = [];

    private $stream = null;

    public int $maximumChunk = 0;

    public bool $production = false;

    public function __construct()
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql' || ! str_ends_with(config('database.connections.mysql.database'), '_test') || config('database.connections.mysql.url')) {
            throw new \LogicException('Disposable MySQL test-only restore.');
        }
    }

    public function inspect(BackupRestoreDrill $drill): array
    {
        return ['configured' => $drill->target_reference === 'isolated:local-fixture', 'authorized' => true, 'isolated' => ! $this->production, 'production' => $this->production, 'fake' => true];
    }

    public function begin(BackupRestoreDrill $drill): void
    {
        if ($this->root !== null || $this->production || ! $this->inspect($drill)['configured']) {
            throw new \LogicException;
        }
        $framework = realpath(storage_path('framework'));
        $expectedFramework = realpath(base_path('storage')).DIRECTORY_SEPARATOR.'framework';
        if ($framework !== $expectedFramework || is_link(storage_path('framework'))) {
            throw new \LogicException;
        }
        $base = $framework.DIRECTORY_SEPARATOR.'testing';
        if (! is_dir($base) && ! mkdir($base, 0700, true)) {
            throw new \RuntimeException;
        }
        $canonical = realpath($base);
        $expected = realpath(storage_path()).DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing';
        if (is_link($base) || $canonical !== $expected) {
            throw new \LogicException;
        }
        $base = $canonical;
        if (! Str::isUuid($drill->workspace_reference)) {
            throw new \LogicException;
        }
        $this->root = $base.DIRECTORY_SEPARATOR.'restore-'.$drill->workspace_reference;
        if (! mkdir($this->root, 0700)) {
            $this->root = null;
            throw new \RuntimeException;
        }
        $this->paths[] = $this->root;
    }

    public function entry(array $entry): void
    {
        if (! $this->root || is_resource($this->stream) || preg_match('/[:*?<>|]/', $entry['path'])) {
            throw new \LogicException;
        }
        $parts = [$this->root, 'root-'.$entry['root_index'], ...explode('/', $entry['path'])];
        $file = array_pop($parts);
        $directory = array_shift($parts);
        foreach ($parts as $part) {
            $directory .= DIRECTORY_SEPARATOR.$part;
            if (! is_dir($directory)) {
                if (! mkdir($directory, 0700)) {
                    throw new \RuntimeException;
                }
                $this->paths[] = $directory;
            }
            if (is_link($directory)) {
                throw new \LogicException;
            }
        }
        $path = $directory.DIRECTORY_SEPARATOR.$file;
        $this->stream = fopen($path, 'xb');
        if (! $this->stream) {
            throw new \RuntimeException;
        }
        chmod($path, 0600);
        $this->paths[] = $path;
    }

    public function write(string $chunk): void
    {
        $this->maximumChunk = max($this->maximumChunk, strlen($chunk));
        if (strlen($chunk) > 65536 || ! is_resource($this->stream) || fwrite($this->stream, $chunk) !== strlen($chunk)) {
            throw new \RuntimeException;
        }
    }

    public function endEntry(array $entry): void
    {
        fflush($this->stream);
        $path = stream_get_meta_data($this->stream)['uri'];
        fclose($this->stream);
        $this->stream = null;
        if (filesize($path) !== $entry['bytes'] || hash_file('sha256', $path) !== $entry['sha256']) {
            throw new \RuntimeException;
        }
        $this->files[] = $entry;
    }

    public function check(array $manifest): array
    {
        if (is_resource($this->stream) || ! ManifestIdentity::equal($manifest['files'], $this->files)) {
            throw new \RuntimeException;
        }

        return ['files_restored' => true, 'file_hashes_match' => true, 'file_count' => count($this->files), 'database_restored' => false, 'application_health_proven' => false];
    }

    public function discard(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
            $this->stream = null;
        }
        foreach (array_reverse($this->paths) as $path) {
            if (! $this->root || ($path !== $this->root && ! str_starts_with($path, $this->root.DIRECTORY_SEPARATOR)) || is_link($path)) {
                throw new \LogicException('Unsafe isolated cleanup path.');
            }
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        $this->paths = $this->files = [];
        $this->root = null;
    }

    public function reconcile(BackupRestoreDrill $drill): bool
    {
        if (! $this->inspect($drill)['configured'] || $this->production || ! Str::isUuid($drill->workspace_reference)) {
            return false;
        }
        if ($this->root !== null) {
            $this->discard();
        }
        $base = realpath(storage_path('framework/testing'));
        if ($base !== realpath(base_path('storage')).DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing' || is_link(storage_path('framework/testing')) || is_link(storage_path('framework'))) {
            return false;
        }
        $root = $base.DIRECTORY_SEPARATOR.'restore-'.$drill->workspace_reference;
        if (is_link($root)) {
            return false;
        }
        if (! file_exists($root)) {
            return true;
        }
        $paths = [$root];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if (count($paths) >= 10000 || $entry->isLink() || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || (! $entry->isFile() && ! $entry->isDir())) {
                return false;
            }
            $paths[] = $path;
        }
        $this->root = $root;
        $this->paths = $paths;
        $this->discard();

        return ! file_exists($root);
    }
}
