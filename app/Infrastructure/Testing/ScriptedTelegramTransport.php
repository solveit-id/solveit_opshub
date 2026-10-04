<?php

namespace App\Infrastructure\Testing;

use App\Infrastructure\Telegram\TelegramResult;
use App\Infrastructure\Telegram\TelegramTransport;
use App\Models\TelegramBot;
use LogicException;

class ScriptedTelegramTransport implements TelegramTransport
{
    public array $calls = [];

    public function __construct(private array $results = [])
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql' || ! str_ends_with(config('database.connections.mysql.database'), '_test') || config('database.connections.mysql.url')) {
            throw new LogicException('Fake Telegram requires isolated testing.');
        }
    }

    public function request(TelegramBot $bot, string $method, array $payload = []): TelegramResult
    {
        $this->calls[] = ['bot_id' => $bot->id, 'method' => $method, 'payload' => $payload, 'fake' => true];
        $r = array_shift($this->results);
        if ($r instanceof TelegramResult) {
            return new TelegramResult($r->outcome, $r->code, $r->httpStatus, $r->messageId, $r->botId, $r->retryAfter, true);
        }

        return $method === 'getMe' ? new TelegramResult('accepted', 'BOT_VERIFIED', 200, botId: '1000000', fake: true) : new TelegramResult('sent', 'ACCEPTED', 200, messageId: 'fake-'.count($this->calls), fake: true);
    }
}
