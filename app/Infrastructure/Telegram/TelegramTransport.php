<?php

namespace App\Infrastructure\Telegram;

use App\Models\TelegramBot;

interface TelegramTransport
{
    public function request(TelegramBot $bot, string $method, array $payload = []): TelegramResult;
}
