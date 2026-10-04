<?php

namespace App\Infrastructure\Connectors\Sftp;

use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Infrastructure\Connectors\ConnectorSecretResolver;
use phpseclib4\Crypt\PublicKeyLoader;
use phpseclib4\Exception\FileSystemException;
use phpseclib4\Exception\TimeoutException;
use phpseclib4\Net\SFTP;

class NativeSftpSession implements SftpSession
{
    public function __construct(private SFTP $client, private ConnectorConfig $config, private ConnectorSecretResolver $secrets) {}

    public function fingerprint(): string
    {
        return $this->safe(function (): string {
            $key = $this->client->getServerPublicHostKey();
            $parts = $key ? explode(' ', $key) : [];
            $blob = isset($parts[1]) ? base64_decode($parts[1], true) : false;
            if ($blob === false) {
                throw new ConnectorFailure(ConnectorReason::HostKeyMismatch);
            }

            return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
        });
    }

    public function authenticate(): void
    {
        // Defense in depth: secrets are never resolved until the verified handshake matches the pin.
        if (! hash_equals($this->config->hostFingerprint, $this->fingerprint())) {
            throw new ConnectorFailure(ConnectorReason::HostKeyMismatch);
        }
        $this->safe(function (): void {
            $secret = $this->secrets->resolve($this->config->secretReference);
            if (! is_string($secret) || $secret === '' || strlen($secret) > 16384) {
                throw new ConnectorFailure(ConnectorReason::NotConfigured);
            }
            $credential = str_contains($secret, 'PRIVATE KEY') ? PublicKeyLoader::loadPrivateKey($secret) : $secret;
            if (! $this->client->login($this->config->accountIdentifier, $credential)) {
                throw new ConnectorFailure(ConnectorReason::AuthFailed);
            }
        });
    }

    public function realpath(string $path): string
    {
        return $this->safe(fn () => $this->client->realpath($path));
    }

    public function lstat(string $path): array
    {
        return $this->safe(fn () => $this->client->lstat($path));
    }

    public function listing(string $path, int $limit): array
    {
        return $this->safe(function () use ($path, $limit): array {
            $count = 0;
            $deadline = hrtime(true) + 15_000_000_000;

            return $this->client->rawlist($path, false, function ($dir, $name) use (&$count, $limit, $deadline): void {
                if (hrtime(true) > $deadline) {
                    throw new ConnectorFailure(ConnectorReason::Timeout);
                }
                if (strlen($name) > 255 || ++$count > $limit + 2) {
                    throw new ConnectorFailure(ConnectorReason::LimitExceeded);
                }
            });
        });
    }

    public function read(string $path, int $offset, int $length): string
    {
        if ($length < 1 || $length > 65536 || $offset < 0) {
            throw new ConnectorFailure(ConnectorReason::LimitExceeded);
        }

        return $this->safe(function () use ($path, $offset, $length): string {
            $data = $this->client->get($path, null, $offset, $length);
            if (! is_string($data) || strlen($data) > $length) {
                throw new ConnectorFailure(ConnectorReason::ArtifactIncomplete);
            }

            return $data;
        });
    }

    public function close(): void
    {
        $this->client->disconnect();
    }

    private function safe(\Closure $call): mixed
    {
        try {
            $this->client->setTimeout(5);

            return $call();
        } catch (ConnectorFailure $error) {
            throw $error;
        } catch (TimeoutException) {
            throw new ConnectorFailure(ConnectorReason::Timeout);
        } catch (FileSystemException $error) {
            throw new ConnectorFailure($error->getCode() === 3 ? ConnectorReason::PermissionDenied : ConnectorReason::ArtifactIncomplete);
        } catch (\Throwable) {
            throw new ConnectorFailure(ConnectorReason::Network);
        }
    }
}
