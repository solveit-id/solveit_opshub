<?php

namespace App\Infrastructure\Telegram;

readonly class TelegramResult
{
    public function __construct(public string $outcome, public string $code, public ?int $httpStatus = null, public ?string $messageId = null, public ?string $botId = null, public ?int $retryAfter = null, public bool $fake = false) {}
}
