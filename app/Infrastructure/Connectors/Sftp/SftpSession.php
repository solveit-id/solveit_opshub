<?php

namespace App\Infrastructure\Connectors\Sftp;

interface SftpSession
{
    public function fingerprint(): string;

    public function authenticate(): void;

    public function realpath(string $path): string;

    public function lstat(string $path): array;

    public function listing(string $path, int $limit): array;

    public function read(string $path, int $offset, int $length): string;

    public function close(): void;
}
