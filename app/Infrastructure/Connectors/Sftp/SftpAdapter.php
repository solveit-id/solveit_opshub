<?php

namespace App\Infrastructure\Connectors\Sftp;

use App\Infrastructure\Connectors\BackupRequest;
use App\Infrastructure\Connectors\Capability;
use App\Infrastructure\Connectors\ConnectorAdapter;
use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Infrastructure\Connectors\ConnectorResult;

class SftpAdapter implements ConnectorAdapter
{
    public function __construct(private SftpSessionFactory $sessions, private RemotePathGuard $paths) {}

    public function fake(): bool
    {
        return $this->sessions->fake();
    }

    public function metadata(ConnectorConfig $config, string $root, string $relative): array
    {
        return $this->session($config, function (SftpSession $session) use ($config, $root, $relative): array {
            [, $stat] = $this->paths->resolve($session, $config, $root, $relative);

            return $stat;
        });
    }

    public function validateConfig(ConnectorConfig $config): ConnectorResult
    {
        return $this->inspect($config, Capability::Connection);
    }

    public function readObservation(ConnectorConfig $config, Capability $capability): ConnectorResult
    {
        return in_array($capability, [Capability::Connection, Capability::SftpRead], true) ? $this->inspect($config, $capability)
            : new ConnectorResult('unsupported', $capability->value, 'UNSUPPORTED_CAPABILITY', fake: $this->sessions->fake());
    }

    public function discoverCapabilities(ConnectorConfig $config): array
    {
        return [$this->inspect($config, Capability::SftpRead),
            new ConnectorResult('unknown', 'file_backup', 'NOT_CONFIGURED', fake: $this->sessions->fake()),
            new ConnectorResult('unsupported', 'database_backup', 'UNSUPPORTED_CAPABILITY', fake: $this->sessions->fake()),
            new ConnectorResult('unsupported', 'full_account_restore', 'UNSUPPORTED_CAPABILITY', fake: $this->sessions->fake())];
    }

    public function requestBackup(ConnectorConfig $config, BackupRequest $request): ConnectorResult
    {
        return new ConnectorResult('unsupported', $request->capability->value, 'UNSUPPORTED_CAPABILITY', fake: $this->sessions->fake());
    }

    public function reconcile(ConnectorConfig $config, BackupRequest $request): ConnectorResult
    {
        return $this->requestBackup($config, $request);
    }

    /** Private worker data only, never provider path/content evidence for UI or Telegram. */
    public function listing(ConnectorConfig $config, string $root, string $relative = '', int $limit = 1000, array $excluded = []): array
    {
        if ($limit < 1 || $limit > 5000) {
            throw new ConnectorFailure(ConnectorReason::LimitExceeded);
        }

        return $this->session($config, function (SftpSession $session) use ($config, $root, $relative, $limit, $excluded): array {
            [$path] = $this->paths->resolve($session, $config, $root, $relative, 2);
            $list = $session->listing($path, $limit);
            $this->paths->resolve($session, $config, $root, $relative, 2);
            $result = [];
            foreach ($list as $name => $entry) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if (! is_string($name) || strlen($name) > 255 || str_contains($name, '/')) {
                    throw new ConnectorFailure(ConnectorReason::PathBlocked);
                }
                $child = ($relative === '' ? '' : $relative.'/').$name;
                if (count($result) >= $limit) {
                    throw new ConnectorFailure(ConnectorReason::LimitExceeded);
                }
                $skip = false;
                foreach ($excluded as $exclude) {
                    $skip = $skip || $child === $exclude || str_starts_with($child, $exclude.'/');
                }
                if ($skip) {
                    $result[] = ['path' => $child, 'type' => 0, 'excluded' => true];

                    continue; // Approved exclusion does not stat or follow the child.
                }
                [, $stat] = $this->paths->resolve($session, $config, $root, $child);
                $result[] = ['path' => $child, 'type' => $stat['type'], 'size' => $stat['size'] ?? null, 'mtime' => $stat['mtime'] ?? null];
            }

            return $result;
        });
    }

    /** Consumer receives at most 64 KiB. Caller owns private sink and discards partial output on failure. */
    public function stream(ConnectorConfig $config, string $root, string $relative, \Closure $consume, int $byteLimit = 104857600, int $seconds = 30): array
    {
        if ($byteLimit < 0 || $seconds < 1 || $seconds > 3600) {
            throw new ConnectorFailure(ConnectorReason::LimitExceeded);
        }

        return $this->session($config, function (SftpSession $session) use ($config, $root, $relative, $consume, $byteLimit, $seconds): array {
            [$path, $initial] = $this->paths->resolve($session, $config, $root, $relative, 1);
            $size = $initial['size'] ?? null;
            if (! is_int($size) || $size < 0 || $size > $byteLimit || ! is_int($initial['mtime'] ?? null)) {
                throw new ConnectorFailure(ConnectorReason::LimitExceeded);
            }
            $hash = hash_init('sha256');
            $offset = 0;
            $deadline = hrtime(true) + $seconds * 1_000_000_000;
            while ($offset < $size) {
                if (hrtime(true) > $deadline) {
                    throw new ConnectorFailure(ConnectorReason::Timeout);
                }
                $this->paths->resolve($session, $config, $root, $relative, 1);
                $length = min(65536, $size - $offset);
                $chunk = $session->read($path, $offset, $length);
                [, $current] = $this->paths->resolve($session, $config, $root, $relative, 1);
                if (strlen($chunk) !== $length || ($current['size'] ?? null) !== $size || ($current['mtime'] ?? null) !== $initial['mtime']) {
                    throw new ConnectorFailure(ConnectorReason::ArtifactIncomplete);
                }
                if (hrtime(true) > $deadline) {
                    throw new ConnectorFailure(ConnectorReason::Timeout);
                }
                hash_update($hash, $chunk);
                $consume($chunk);
                $offset += $length;
            }
            [, $final] = $this->paths->resolve($session, $config, $root, $relative, 1);
            if (($final['size'] ?? null) !== $size || ($final['mtime'] ?? null) !== $initial['mtime']) {
                throw new ConnectorFailure(ConnectorReason::ArtifactIncomplete);
            }

            return ['path' => $relative, 'bytes' => $offset, 'mtime' => $initial['mtime'], 'sha256' => hash_final($hash), 'fake' => $this->sessions->fake()];
        });
    }

    private function inspect(ConnectorConfig $config, Capability $capability): ConnectorResult
    {
        try {
            if ($config->kind !== 'sftp') {
                throw new ConnectorFailure(ConnectorReason::NotConfigured);
            }
            $count = 0;
            foreach ($config->roots as $root) {
                $count += count($this->listing($config, $root));
            }

            return new ConnectorResult('supported', $capability->value, null, fake: $this->sessions->fake(), evidence: ['file_count' => $count]);
        } catch (ConnectorFailure $failure) {
            $status = match ($failure->reason) {
                ConnectorReason::NotConfigured => 'not_configured',
                ConnectorReason::AuthFailed, ConnectorReason::PermissionDenied => 'permission_denied',
                default => 'fail',
            };

            return new ConnectorResult($status, $capability->value, $failure->reason->value, fake: $this->sessions->fake(), retryable: in_array($failure->reason, [ConnectorReason::Network, ConnectorReason::Timeout], true));
        } catch (\Throwable) {
            return new ConnectorResult('fail', $capability->value, 'RESPONSE_INVALID', fake: $this->sessions->fake());
        }
    }

    private function session(ConnectorConfig $config, \Closure $operation): mixed
    {
        if ($config->kind !== 'sftp' || ($this->sessions->fake() && ! app()->environment('testing'))) {
            throw new ConnectorFailure(ConnectorReason::NotConfigured);
        }
        $session = $this->sessions->open($config);
        try {
            if (! hash_equals($config->hostFingerprint, $session->fingerprint())) {
                throw new ConnectorFailure(ConnectorReason::HostKeyMismatch);
            }
            $session->authenticate();

            return $operation($session);
        } finally {
            $session->close();
        }
    }
}
