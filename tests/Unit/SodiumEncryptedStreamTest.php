<?php

namespace Tests\Unit;

use App\Infrastructure\Backup\SodiumEncryptedStream;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use Tests\TestCase;

class SodiumEncryptedStreamTest extends TestCase
{
    private function envelope(): array
    {
        $cipher = new SodiumEncryptedStream;
        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        $file = fopen('php://temp/maxmemory:1048576', 'w+b');
        $payload = str_repeat('fictitious-private-data', 6000);
        $largest = 0;
        $result = $cipher->seal($key, 'fictitious-run-identity', function ($consume) use ($payload): array {
            for ($offset = 0; $offset < strlen($payload); $offset += 65536) {
                $consume(substr($payload, $offset, 65536));
            }

            return ['sha256' => hash('sha256', $payload), 'fake' => true, 'private_path' => 'config.php'];
        }, function ($chunk) use ($file, &$largest): void {
            $largest = max($largest, strlen($chunk));
            fwrite($file, $chunk);
        });
        rewind($file);
        $bytes = stream_get_contents($file);
        fclose($file);

        return [$cipher, $key, $payload, $bytes, $result, $largest];
    }

    public function test_authenticated_stream_round_trips_payload_and_private_manifest_in_bounded_cipher_chunks(): void
    {
        [$cipher, $key, $payload, $bytes, $sealed, $largest] = $this->envelope();
        $this->assertStringNotContainsString('fictitious-private-data', $bytes);
        $this->assertStringNotContainsString('config.php', $bytes);
        $this->assertLessThanOrEqual(65536, $largest);
        $file = fopen('php://temp', 'w+b');
        fwrite($file, $bytes);
        rewind($file);
        $decoded = '';
        try {
            $opened = $cipher->open($file, $key, 'fictitious-run-identity', function ($chunk) use (&$decoded): void {
                $decoded .= $chunk;
            });
        } finally {
            fclose($file);
        }
        $this->assertSame($payload, $decoded);
        $this->assertSame($sealed, $opened);
        $this->assertSame(hash('sha256', $bytes), $opened['encrypted_sha256']);
    }

    public function test_tamper_wrong_key_identity_truncation_frame_reordering_and_trailing_bytes_are_rejected(): void
    {
        [$cipher, $key, , $bytes] = $this->envelope();
        $start = strlen(SodiumEncryptedStream::MAGIC) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $firstLength = 5 + unpack('N', substr($bytes, $start + 1, 4))[1];
        $secondLength = 5 + unpack('N', substr($bytes, $start + $firstLength + 1, 4))[1];
        $flipped = $bytes;
        $flipped[$start + 10] = chr(ord($flipped[$start + 10]) ^ 1);
        $reordered = substr($bytes, 0, $start).substr($bytes, $start + $firstLength, $secondLength).substr($bytes, $start, $firstLength).substr($bytes, $start + $firstLength + $secondLength);
        foreach ([[$flipped, $key, 'fictitious-run-identity'], [$bytes, random_bytes(32), 'fictitious-run-identity'], [$bytes, $key, 'another-tenant'],
            [substr($bytes, 0, -17), $key, 'fictitious-run-identity'], [$reordered, $key, 'fictitious-run-identity'], [$bytes.'x', $key, 'fictitious-run-identity'],
            [substr($bytes, 0, $start).substr($bytes, $start + $firstLength), $key, 'fictitious-run-identity']] as [$data, $candidateKey, $context]) {
            $file = fopen('php://temp', 'w+b');
            fwrite($file, $data);
            rewind($file);
            try {
                $cipher->open($file, $candidateKey, $context, fn () => null);
                $this->fail('Corrupt envelope passed');
            } catch (ConnectorFailure $error) {
                $this->assertSame(ConnectorReason::IntegrityFailed, $error->reason);
            } finally {
                fclose($file);
            }
        }
    }

    public function test_large_cipher_and_authenticated_read_are_bounded_without_plaintext_disk_spool(): void
    {
        $cipher = new SodiumEncryptedStream;
        $key = random_bytes(32);
        $file = tmpfile();
        $size = 1073741824 + 65537;
        $largest = $decodedBytes = 0;
        $baseline = memory_get_usage(true);
        memory_reset_peak_usage();
        try {
            $sealed = $cipher->seal($key, 'large-fictitious-fixture', function ($consume) use ($size): array {
                $hash = hash_init('sha256');
                for ($offset = 0; $offset < $size; $offset += 65536) {
                    $chunk = str_repeat('x', min(65536, $size - $offset));
                    hash_update($hash, $chunk);
                    $consume($chunk);
                }

                return ['sha256' => hash_final($hash), 'fake' => true];
            }, function ($chunk) use ($file, &$largest): void {
                $largest = max($largest, strlen($chunk));
                if (fwrite($file, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Fixture write failed.');
                }
            });
            rewind($file);
            $opened = $cipher->open($file, $key, 'large-fictitious-fixture', function ($chunk) use (&$decodedBytes): void {
                $decodedBytes += strlen($chunk);
            });
            $growth = memory_get_peak_usage(true) - $baseline;
            $this->assertSame($size, $decodedBytes);
            $this->assertSame($sealed, $opened);
            $this->assertSame($opened['encrypted_bytes'], fstat($file)['size']);
            $this->assertLessThanOrEqual(65536, $largest);
            $this->assertLessThan(16777216, $growth);
            fwrite(STDOUT, "\nSecretstream generated fixture: payload={$size}, encrypted={$opened['encrypted_bytes']}, peak_growth={$growth}, max_chunk={$largest}\n");
        } finally {
            fclose($file);
            sodium_memzero($key);
        }
    }

    public function test_authenticated_reader_enforces_total_byte_and_wall_clock_deadline(): void
    {
        [$cipher, $key, , $bytes] = $this->envelope();
        foreach (['bytes', 'deadline'] as $case) {
            $file = fopen('php://temp', 'w+b');
            fwrite($file, $bytes);
            rewind($file);
            try {
                $cipher->open($file, $key, 'fictitious-run-identity', $case === 'deadline' ? fn () => usleep(1010000) : fn () => null, $case === 'bytes' ? 100 : 1048576, 1);
                $this->fail('Reader budget passed');
            } catch (ConnectorFailure $error) {
                $this->assertSame($case === 'bytes' ? ConnectorReason::LimitExceeded : ConnectorReason::Timeout, $error->reason);
            } finally {
                fclose($file);
            }
        }
    }
}
