<?php

namespace App\Infrastructure\Connectors\Cpanel;

use App\Infrastructure\Connectors\ConnectorReason;

final readonly class CpanelResponse
{
    public function __construct(public int $httpStatus, public array $body = [], public ?ConnectorReason $error = null, public bool $fake = false) {}
}
