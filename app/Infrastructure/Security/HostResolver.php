<?php

namespace App\Infrastructure\Security;

interface HostResolver
{
    public function resolve(string $host): array;
}
