<?php

namespace App\Infrastructure\Connectors;

interface ConnectorSecretResolver
{
    public function resolve(string $reference): string;
}
