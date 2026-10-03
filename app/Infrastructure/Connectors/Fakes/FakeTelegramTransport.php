<?php

namespace App\Infrastructure\Connectors\Fakes;

use App\Infrastructure\Connectors\ConnectorResult;

class FakeTelegramTransport
{
    public function send(string $scenario = 'accepted'): ConnectorResult
    {
        return match ($scenario) {
            'rate_limited' => new ConnectorResult('retrying', 'telegram_send', 'RATE_LIMITED', 'Fake Telegram rate limit.'),
            'forbidden' => new ConnectorResult('failed', 'telegram_send', 'PERMISSION_DENIED', 'Fake Telegram destination denied.'),
            default => new ConnectorResult('accepted', 'telegram_send', null, 'Fake Telegram accepted message.'),
        };
    }
}
