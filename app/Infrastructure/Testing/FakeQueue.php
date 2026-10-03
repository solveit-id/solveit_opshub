<?php

namespace App\Infrastructure\Testing;

class FakeQueue
{
    /** @var array<int, array{name: string, payload: array, status: string}> */
    private array $jobs = [];

    public function dispatch(string $name, array $payload = [], bool $shouldFail = false): void
    {
        $this->jobs[] = [
            'name' => $name,
            'payload' => $payload,
            'status' => $shouldFail ? 'failed' : 'queued',
        ];
    }

    /** @return array<int, array{name: string, payload: array, status: string}> */
    public function jobs(): array
    {
        return $this->jobs;
    }
}
