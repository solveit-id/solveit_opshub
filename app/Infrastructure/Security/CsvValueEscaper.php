<?php

namespace App\Infrastructure\Security;

class CsvValueEscaper
{
    public function escape(string $value): string
    {
        return preg_match('/^\s*[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
