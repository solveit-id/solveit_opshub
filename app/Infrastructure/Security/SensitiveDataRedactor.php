<?php

namespace App\Infrastructure\Security;

class SensitiveDataRedactor
{
    private const SensitiveKeys = [
        'authorization',
        'cookie',
        'password',
        'token',
        'secret',
        'api_key',
        'private_key',
        'connection_string',
        'signed_url',
    ];

    public function redact(string $value): string
    {
        $value = preg_replace('/(bot)[0-9]{6,}:[A-Za-z0-9_-]+/i', '$1[REDACTED]', $value) ?? $value;
        $value = preg_replace('/([?&](?:token|api[_-]?key|secret|password)=)[^&\s]+/i', '$1[REDACTED]', $value) ?? $value;
        $value = preg_replace('/(Bearer\s+)[^\s]+/i', '$1[REDACTED]', $value) ?? $value;

        return $value;
    }

    public function redactArray(array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SensitiveKeys, true)) {
                $redacted[$key] = '[REDACTED]';

                continue;
            }

            $redacted[$key] = match (true) {
                is_array($value) => $this->redactArray($value),
                is_string($value) => $this->redact($value),
                default => $value,
            };
        }

        return $redacted;
    }
}
