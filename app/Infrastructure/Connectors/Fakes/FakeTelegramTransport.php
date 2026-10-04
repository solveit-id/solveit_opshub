<?php

namespace App\Infrastructure\Connectors\Fakes;

class FakeTelegramTransport
{
    public function send(string $scenario = 'accepted'): FixtureResult
    {
        return match ($scenario) {
            'rate_limited' => new FixtureResult('retrying', 'telegram_send', 'RATE_LIMITED', 'Fake Telegram rate limit.'),
            'forbidden' => new FixtureResult('failed', 'telegram_send', 'PERMISSION_DENIED', 'Fake Telegram destination denied.'),
            default => new FixtureResult('accepted', 'telegram_send', null, 'Fake Telegram accepted message.'),
        };
    }
}
