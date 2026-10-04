<?php

namespace App\Infrastructure\Testing;

use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\Cpanel\CpanelRead;
use App\Infrastructure\Connectors\Cpanel\CpanelResponse;
use App\Infrastructure\Connectors\Cpanel\CpanelTransport;
use LogicException;

class ScriptedCpanelTransport implements CpanelTransport
{
    public array $calls = [];

    public function __construct(public array $responses) {}

    public function read(ConnectorConfig $config, CpanelRead $operation): CpanelResponse
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Scripted cPanel transport is test-only.');
        }
        $this->calls[] = [$operation->value, $config->secretReference];
        $r = array_shift($this->responses) ?? new CpanelResponse(503);

        return new CpanelResponse($r->httpStatus, $r->body, $r->error, true);
    }
}
