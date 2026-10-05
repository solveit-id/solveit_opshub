<?php

namespace App\Infrastructure\Backup;

use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;

/** Versioned libsodium secretstream envelope; final tag authenticates the private manifest. */
class SodiumEncryptedStream
{
    public const MAGIC = "OPSHUB-SECRETSTREAM/1\n";

    public function seal(#[\SensitiveParameter] string $key, string $context, \Closure $producer, \Closure $consume): array
    {
        $this->key($key);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $cipherHash = hash_init('sha256');
        $plainHash = hash_init('sha256');
        $cipherBytes = $plainBytes = $index = 0;
        $write = function (string $data) use ($consume, $cipherHash, &$cipherBytes): void {
            hash_update($cipherHash, $data);
            $cipherBytes += strlen($data);
            for ($offset = 0; $offset < strlen($data); $offset += 65536) {
                $consume(substr($data, $offset, 65536));
            }
        };
        $write(self::MAGIC.$header);
        $frame = function (string $kind, string $plain, int $tag) use (&$state, &$index, $context, $write): void {
            if (strlen($plain) > 65536 || $index >= 0xFFFFFFFF) {
                throw new ConnectorFailure(ConnectorReason::LimitExceeded);
            }
            $head = $kind.pack('N', strlen($plain) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            $encrypted = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, $context.pack('N', $index++).$head, $tag);
            $write($head.$encrypted);
        };
        try {
            $manifest = $producer(function (string $chunk) use ($frame, $plainHash, &$plainBytes): void {
                hash_update($plainHash, $chunk);
                $plainBytes += strlen($chunk);
                $frame('D', $chunk, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            });
            $digest = hash_final($plainHash);
            if (($manifest['sha256'] ?? null) !== $digest) {
                throw new ConnectorFailure(ConnectorReason::IntegrityFailed);
            }
            $manifest = [...$manifest, 'payload_bytes' => $plainBytes, 'payload_sha256' => $digest];
            $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($json) > 8388608) {
                throw new ConnectorFailure(ConnectorReason::LimitExceeded);
            }
            for ($offset = 0; $offset < strlen($json); $offset += 65536) {
                $final = $offset + 65536 >= strlen($json);
                $frame('M', substr($json, $offset, 65536), $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            }

            return ['manifest' => $manifest, 'bytes' => $plainBytes, 'sha256' => $digest, 'encrypted_bytes' => $cipherBytes, 'encrypted_sha256' => hash_final($cipherHash)];
        } finally {
            sodium_memzero($state);
        }
    }

    /** Caller owns stream; consumers must discard output unless final authentication succeeds. */
    public function open($stream, #[\SensitiveParameter] string $key, string $context, \Closure $consume, int $maximumBytes = 2199023255552, int $maximumSeconds = 3600): array
    {
        $this->key($key);
        $cipherHash = hash_init('sha256');
        $plainHash = hash_init('sha256');
        $cipherBytes = $plainBytes = $index = 0;
        if ($maximumBytes < 1 || $maximumSeconds < 1 || $maximumSeconds > 3600) {
            throw new ConnectorFailure(ConnectorReason::LimitExceeded);
        }
        $deadline = hrtime(true) + $maximumSeconds * 1_000_000_000;
        $read = function (int $length) use ($stream, $cipherHash, &$cipherBytes, $maximumBytes, $deadline): string {
            if ($cipherBytes + $length > $maximumBytes) {
                throw new ConnectorFailure(ConnectorReason::LimitExceeded);
            }
            if (hrtime(true) >= $deadline) {
                throw new ConnectorFailure(ConnectorReason::Timeout);
            }
            $bytes = $this->readExact($stream, $length);
            if (hrtime(true) >= $deadline) {
                throw new ConnectorFailure(ConnectorReason::Timeout);
            }
            hash_update($cipherHash, $bytes);
            $cipherBytes += strlen($bytes);

            return $bytes;
        };
        if ($read(strlen(self::MAGIC)) !== self::MAGIC) {
            throw new ConnectorFailure(ConnectorReason::IntegrityFailed);
        }
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($read(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
        $metadata = '';
        $seenMetadata = $final = false;
        try {
            while (! $final) {
                $head = $read(5);
                $kind = $head[0];
                $length = unpack('N', substr($head, 1))[1];
                if (! in_array($kind, ['D', 'M'], true) || $length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                    || $length > 65536 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $index >= 0xFFFFFFFF || ($kind === 'D' && $seenMetadata)) {
                    throw new ConnectorFailure(ConnectorReason::IntegrityFailed);
                }
                $decoded = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $read($length), $context.pack('N', $index++).$head);
                if ($decoded === false) {
                    throw new ConnectorFailure(ConnectorReason::IntegrityFailed);
                }
                [$plain, $tag] = $decoded;
                if (! in_array($tag, [SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL], true)) {
                    throw new ConnectorFailure(ConnectorReason::IntegrityFailed);
                }
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
                if ($kind === 'D') {
                    if ($final) {
                        throw new ConnectorFailure(ConnectorReason::IntegrityFailed);
                    }
                    hash_update($plainHash, $plain);
                    $plainBytes += strlen($plain);
                    $consume($plain);
                } else {
                    $seenMetadata = true;
                    $metadata .= $plain;
                    if (strlen($metadata) > 8388608) {
                        throw new ConnectorFailure(ConnectorReason::LimitExceeded);
                    }
                }
            }
            if (fread($stream, 1) !== '' || ! feof($stream)) {
                throw new ConnectorFailure(ConnectorReason::IntegrityFailed);
            }
            $manifest = json_decode($metadata, true, 64, JSON_THROW_ON_ERROR);
            $digest = hash_final($plainHash);
            if (! is_array($manifest) || ($manifest['payload_bytes'] ?? null) !== $plainBytes || ($manifest['payload_sha256'] ?? null) !== $digest) {
                throw new ConnectorFailure(ConnectorReason::IntegrityFailed);
            }

            return ['manifest' => $manifest, 'bytes' => $plainBytes, 'sha256' => $digest, 'encrypted_bytes' => $cipherBytes, 'encrypted_sha256' => hash_final($cipherHash)];
        } finally {
            sodium_memzero($state);
        }
    }

    public function readExact($stream, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $part = fread($stream, min(65536, $length - strlen($data)));
            if ($part === false || $part === '') {
                throw new ConnectorFailure(ConnectorReason::IntegrityFailed);
            }
            $data .= $part;
        }

        return $data;
    }

    private function key(#[\SensitiveParameter] string $key): void
    {
        if (! extension_loaded('sodium') || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new ConnectorFailure(ConnectorReason::NotConfigured);
        }
    }
}
