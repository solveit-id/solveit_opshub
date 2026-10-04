<?php

namespace App\Application\ClientTemplates;

use App\Infrastructure\Security\SensitiveDataRedactor;
use App\Rules\RejectSecretBearingValue;
use Illuminate\Validation\ValidationException;

class PlaintextRenderer
{
    public function validate(string $body, array $allowed = TemplateCatalog::VARIABLES): void
    {
        if (trim($body) === '' || mb_strlen($body) > 20000) {
            throw ValidationException::withMessages(['body' => 'Template wajib dan maksimal 20000 karakter.']);
        }
        $this->safe($body);
        preg_match_all('/\{\{([a-z_]+)\}\}|\[\[if ([a-z_]+)\]\]|\[\[endif\]\]/', $body, $tokens, PREG_SET_ORDER);
        $open = false;
        foreach ($tokens as $token) {
            $name = $token[1] ?? '';
            if (str_starts_with($token[0], '[[if ')) {
                if ($open) {
                    throw ValidationException::withMessages(['body' => 'Nested conditional tidak didukung.']);
                }
                $open = true;
                $name = $token[2];
            } elseif ($token[0] === '[[endif]]') {
                if (! $open) {
                    throw ValidationException::withMessages(['body' => 'Conditional tidak berpasangan.']);
                }
                $open = false;
            }
            if ($name !== '' && ! in_array($name, $allowed, true)) {
                throw ValidationException::withMessages(['body' => 'Variable tidak dikenal: '.$name]);
            }
        }
        $remaining = preg_replace('/\{\{[a-z_]+\}\}|\[\[if [a-z_]+\]\]|\[\[endif\]\]/', '', $body);
        if ($open || preg_match('/[{}]|\[\[|\]\]/', $remaining)) {
            throw ValidationException::withMessages(['body' => 'Syntax template invalid.']);
        }
    }

    public function render(string $body, array $variables, array $mandatory): array
    {
        $this->validate($body);
        $clean = [];
        foreach ($variables as $key => $value) {
            if (! in_array($key, TemplateCatalog::VARIABLES, true)) {
                throw ValidationException::withMessages(['variables' => 'Variable tidak dikenal.']);
            }
            $clean[$key] = $value === null ? '' : $this->safe((string) $value);
        }
        $missing = array_values(array_filter($mandatory, fn ($key) => ($clean[$key] ?? '') === ''));
        $body = preg_replace_callback('/\[\[if ([a-z_]+)\]\](.*?)\[\[endif\]\]/s', fn ($m) => ($clean[$m[1]] ?? '') === '' ? '' : $m[2], $body);
        preg_match_all('/\{\{([a-z_]+)\}\}/', $body, $used);
        foreach ($used[1] as $key) {
            if (($clean[$key] ?? '') === '') {
                $missing[] = $key;
            }
        }
        $body = preg_replace_callback('/\{\{([a-z_]+)\}\}/', fn ($m) => $clean[$m[1]] ?? '', $body);
        $body = trim(preg_replace('/\n{3,}/', "\n\n", $body));
        $missing = array_values(array_unique($missing));
        if (preg_match('/\{\{|\b(?:undefined|null)\b/i', $body)) {
            $missing[] = 'invalid_rendered_text';
        }

        return ['body' => $body, 'variables' => $clean, 'missing' => $missing, 'status' => $missing === [] ? 'ready' : 'blocked_missing_data'];
    }

    public function safe(string $value): string
    {
        validator(['text' => $value], ['text' => [new RejectSecretBearingValue]])->validate();
        if (app(SensitiveDataRedactor::class)->redact($value) !== $value || preg_match('/(?:\b\d{6,}:[A-Za-z0-9_-]{20,}\b|-----BEGIN|\b(?:password|api[_-]?key|secret|token)\s*[:=]\s*\S+|https?:\/\/|\bvulnerabilit(?:y|ies)\b|\bCVE-\d)/i', $value)) {
            throw ValidationException::withMessages(['text' => 'Client body tidak boleh memuat secret, URL atau detail keamanan sensitif; gunakan instruksi akses aman berupa teks.']);
        }
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = preg_replace('/[\p{Z}\t]+/u', ' ', $value);

        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }
}
