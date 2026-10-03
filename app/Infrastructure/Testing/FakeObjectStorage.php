<?php

namespace App\Infrastructure\Testing;

use LogicException;

class FakeObjectStorage
{
    /** @var array<string, string> */
    private array $objects = [];

    /** @return array{sha256: string, size: int} */
    public function put(string $key, string $contents): array
    {
        $this->objects[$key] = $contents;

        return [
            'sha256' => hash('sha256', $contents),
            'size' => strlen($contents),
        ];
    }

    public function get(string $key): string
    {
        if (! array_key_exists($key, $this->objects)) {
            throw new LogicException('Fake object does not exist.');
        }

        return $this->objects[$key];
    }
}
