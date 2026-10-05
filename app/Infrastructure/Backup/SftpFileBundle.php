<?php

namespace App\Infrastructure\Backup;

use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Infrastructure\Connectors\Sftp\SftpAdapter;

/** Private framed archive, not a database dump. No artifact-sized memory buffer. */
class SftpFileBundle
{
    public const MAGIC = "OPSHUB-FILES/1\n";

    public function __construct(private SftpAdapter $source, private TransferClock $clock) {}

    public function fake(): bool
    {
        return $this->source->fake();
    }

    public function stream(ConnectorConfig $config, array $policy, string $runReference, \Closure $consume, array &$manifest): array
    {
        $budget = new TransferBudget($this->clock, $policy['bytes_per_second'], $policy['max_bytes'], $policy['max_seconds']);
        $manifest = ['format' => 'opshub-files-v1', 'run_reference' => $runReference, 'source_timestamp' => now('UTC')->toIso8601String(),
            'fake' => $this->fake(), 'files' => [], 'directories' => [], 'exclusions' => [], 'errors' => [], 'file_count' => 0, 'bytes' => 0,
            'covered_scopes' => ['files'], 'coverage_gaps' => array_values(array_diff($policy['required_scopes'], ['files']))];
        $inventory = [];
        $seen = [];
        $walk = function (int $index, string $path, int $depth = 0) use (&$walk, &$inventory, &$seen, &$manifest, $config, $policy, $budget): void {
            $budget->remainingSeconds();
            if ($depth > 64 || count($seen) >= 5000) {
                throw new ConnectorFailure(ConnectorReason::LimitExceeded);
            }
            $key = $index.':'.$path;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            foreach ($policy['excluded_paths'] as $excluded) {
                if ($path === $excluded || str_starts_with($path, $excluded.'/')) {
                    $manifest['exclusions'][] = ['root_index' => $index, 'path' => $path, 'reason' => 'policy'];

                    return;
                }
            }
            $root = $config->roots[$index] ?? throw new ConnectorFailure(ConnectorReason::PathBlocked);
            try {
                $stat = $this->source->metadata($config, $root, $path);
                if ($stat['type'] === 2) {
                    $manifest['directories'][] = ['root_index' => $index, 'path' => $path, 'mtime' => $stat['mtime'] ?? null];
                    foreach ($this->source->listing($config, $root, $path, 5000, $policy['excluded_paths']) as $child) {
                        $walk($index, $child['path'], $depth + 1);
                    }
                } elseif ($stat['type'] === 1 && is_int($stat['size'] ?? null) && is_int($stat['mtime'] ?? null)) {
                    $inventory[] = ['root_index' => $index, 'path' => $path, 'bytes' => $stat['size'], 'mtime' => $stat['mtime']];
                } else {
                    throw new ConnectorFailure(ConnectorReason::PathBlocked);
                }
            } catch (ConnectorFailure $failure) {
                $manifest['errors'][] = ['root_index' => $index, 'path' => $path, 'reason_code' => $failure->reason->value];
            }
        };
        foreach ($policy['included_paths'] as $included) {
            $walk($included['root_index'], $included['path']);
        }
        if ($manifest['errors']) {
            foreach ($manifest['errors'] as $error) {
                if (in_array($error['reason_code'], ['AUTH_FAILED', 'PERMISSION_DENIED'], true)) {
                    throw new ConnectorFailure(ConnectorReason::from($error['reason_code']));
                }
            }
            throw new ConnectorFailure(ConnectorReason::ArtifactIncomplete);
        }
        usort($inventory, fn ($a, $b) => [$a['root_index'], $a['path']] <=> [$b['root_index'], $b['path']]);
        if (array_sum(array_column($inventory, 'bytes')) > $policy['max_bytes']) {
            throw new ConnectorFailure(ConnectorReason::LimitExceeded);
        }
        $hash = hash_init('sha256');
        $bundleBytes = 0;
        $emit = function (string $chunk) use ($consume, $budget, $hash, &$bundleBytes): void {
            $budget->consume($chunk, function (string $chunk) use ($consume, $hash, &$bundleBytes): void {
                hash_update($hash, $chunk);
                $bundleBytes += strlen($chunk);
                $consume($chunk);
            });
        };
        $header = function (array $record) use ($emit): void {
            $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($json) > 8192) {
                throw new ConnectorFailure(ConnectorReason::LimitExceeded);
            }
            $emit(pack('N', strlen($json)).$json);
        };
        $emit(self::MAGIC);
        foreach ($inventory as $entry) {
            try {
                $budget->remainingSeconds();
                $stat = $this->source->metadata($config, $config->roots[$entry['root_index']], $entry['path']);
                if (($stat['size'] ?? null) !== $entry['bytes'] || ($stat['mtime'] ?? null) !== $entry['mtime']) {
                    throw new ConnectorFailure(ConnectorReason::ArtifactIncomplete);
                }
                $header(['kind' => 'file', ...$entry]);
                $result = $this->source->stream($config, $config->roots[$entry['root_index']], $entry['path'], $emit, $entry['bytes'], $budget->remainingSeconds());
                if ($result['fake'] !== $this->fake() || $result['bytes'] !== $entry['bytes'] || $result['mtime'] !== $entry['mtime']) {
                    throw new ConnectorFailure(ConnectorReason::ArtifactIncomplete);
                }
                $emit(hex2bin($result['sha256']));
                $manifest['files'][] = [...$entry, 'sha256' => $result['sha256'], 'status' => 'read'];
                $manifest['bytes'] += $result['bytes'];
                $manifest['file_count']++;
            } catch (ConnectorFailure $failure) {
                $manifest['errors'][] = [...$entry, 'reason_code' => $failure->reason->value];
                throw $failure; // Caller discards the entire partial object, never a silent successful bundle.
            }
        }
        foreach ($manifest['directories'] as $directory) {
            $budget->remainingSeconds();
            $stat = $this->source->metadata($config, $config->roots[$directory['root_index']], $directory['path']);
            if (($stat['mtime'] ?? null) !== $directory['mtime']) {
                $manifest['errors'][] = [...$directory, 'reason_code' => 'ARTIFACT_INCOMPLETE'];
                throw new ConnectorFailure(ConnectorReason::ArtifactIncomplete);
            }
        }
        $header(['kind' => 'end', 'file_count' => $manifest['file_count']]);
        $manifest['bundle_bytes'] = $bundleBytes;
        $manifest['sha256'] = hash_final($hash);

        return $manifest;
    }
}
