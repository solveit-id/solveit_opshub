<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class RejectSecretBearingValue implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $patterns = [
            '/-----BEGIN (?:[A-Z ]+)?PRIVATE KEY-----/i',
            '/\bBearer\s+\S+/i',
            '/\bbot\d{6,}:[A-Za-z0-9_-]+/i',
            '/[?&](?:token|api[_-]?key|secret|password)=/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                $fail('Kolom ini tidak boleh menyimpan token, password, atau private key. Gunakan secret reference yang dikelola secara terpisah.');

                return;
            }
        }
    }
}
