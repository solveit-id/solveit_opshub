<?php

namespace App\Infrastructure\Telegram;

interface TelegramSecretResolver
{
    public function resolve(string $reference): string;
}
