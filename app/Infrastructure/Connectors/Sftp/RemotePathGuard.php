<?php

namespace App\Infrastructure\Connectors\Sftp;

use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;

class RemotePathGuard
{
    public function resolve(SftpSession $session, ConnectorConfig $config, string $root, string $relative, ?int $type = null): array
    {
        if (! in_array($root, $config->roots, true) || ! $this->canonical($root, true) || ! $this->canonical($relative, false)) {
            $this->blocked();
        }
        $rootStat = $session->lstat($root);
        if (($rootStat['type'] ?? null) !== 2 || $session->realpath($root) !== $root) {
            $this->blocked();
        }
        $path = $root;
        $stat = $rootStat;
        $parts = $relative === '' ? [] : explode('/', $relative);
        foreach ($parts as $index => $part) {
            $path .= '/'.$part;
            $stat = $session->lstat($path);
            if (! in_array($stat['type'] ?? null, [1, 2], true)
                || ($index < count($parts) - 1 && $stat['type'] !== 2)
                || $session->realpath($path) !== $path) {
                $this->blocked();
            }
        }
        if ($type !== null && ($stat['type'] ?? null) !== $type) {
            $this->blocked();
        }

        return [$path, $stat];
    }

    private function canonical(string $path, bool $root): bool
    {
        if (strlen($path) > 512 || preg_match('/[\\\\\x00-\x1f\x7f%]/', $path) || str_contains($path, '//')) {
            return false;
        }
        if ($root && (! str_starts_with($path, '/') || $path === '/' || str_ends_with($path, '/'))) {
            return false;
        }
        if (! $root && (str_starts_with($path, '/') || ($path !== '' && str_ends_with($path, '/')))) {
            return false;
        }
        foreach (explode('/', ltrim($path, '/')) as $part) {
            if ($part === '.' || $part === '..') {
                return false;
            }
        }

        return true;
    }

    private function blocked(): never
    {
        throw new ConnectorFailure(ConnectorReason::PathBlocked);
    }
}
