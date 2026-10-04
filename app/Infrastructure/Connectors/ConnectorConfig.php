<?php

namespace App\Infrastructure\Connectors;

use InvalidArgumentException;

/** Non-secret immutable snapshot. Secret resolution belongs exclusively to a worker. */
final readonly class ConnectorConfig
{
    public function __construct(
        public int $organizationId,
        public int $hostingAccountId,
        public string $kind,
        public string $endpoint,
        public string $accountIdentifier,
        public string $secretReference,
        public array $roots = [],
        public ?string $hostFingerprint = null,
        public int $version = 1,
    ) {
        if ($organizationId < 1 || $hostingAccountId < 1 || $version < 1
            || ! in_array($kind, ['cpanel', 'sftp'], true)
            || ! preg_match('/^(?:env:OPSHUB_CONNECTOR_[A-Z0-9_]{3,100}|vault:[a-zA-Z0-9_\/-]{1,180})$/D', $secretReference)
            || ! preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D', $accountIdentifier)) {
            throw new InvalidArgumentException('Invalid non-secret connector configuration.');
        }
        $parts = parse_url($endpoint);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\\\\]/', $endpoint)
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || $parts['scheme'] !== ($kind === 'cpanel' ? 'https' : 'sftp')
            || ($kind === 'sftp' && (! $hostFingerprint || ! preg_match('/^SHA256:[A-Za-z0-9+\/]{43}=?$/D', $hostFingerprint)))) {
            throw new InvalidArgumentException('Invalid connector endpoint or fingerprint.');
        }
        foreach ($roots as $root) {
            if (! is_string($root) || ! str_starts_with($root, '/') || $root === '/' || strlen($root) > 512
                || preg_match('/(?:^|\/)\.\.?(?:\/|$)|[\x00-\x1f\\\\]/', $root)) {
                throw new InvalidArgumentException('Invalid allowed SFTP root.');
            }
        }
        if ($kind === 'sftp' && $roots === []) {
            throw new InvalidArgumentException('SFTP requires an explicit allowed root.');
        }
    }
}
