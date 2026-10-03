<?php

namespace App\Infrastructure\Security;

class WebhookSignatureVerifier
{
    public function isValid(string $payload, ?string $signature, string $secret): bool
    {
        if ($signature === null || $signature === '' || $secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }
}
