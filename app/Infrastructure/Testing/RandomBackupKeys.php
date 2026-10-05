<?php

namespace App\Infrastructure\Testing;

use App\Infrastructure\Backup\BackupKeyResolver;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;

/** Random per test, never persisted. Production secret-manager binding is explicitly absent. */
class RandomBackupKeys implements BackupKeyResolver
{
    public string $key;

    public bool $unavailable = false;

    public function __construct()
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Encryption-key fixture is test-only.');
        }
        $this->key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
    }

    public function reference(int $organizationId, string $destination): ?string
    {
        return 'vault:fixture/backup/'.$organizationId;
    }

    public function resolve(string $reference): string
    {
        if ($this->unavailable || ! preg_match('/^vault:fixture\/backup\/[1-9][0-9]*$/D', $reference)) {
            throw new ConnectorFailure(ConnectorReason::NotConfigured);
        }

        return $this->key;
    }
}
