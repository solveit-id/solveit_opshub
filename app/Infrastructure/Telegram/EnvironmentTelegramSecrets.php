<?php

namespace App\Infrastructure\Telegram;

use Illuminate\Support\Env;
use LogicException;

class EnvironmentTelegramSecrets implements TelegramSecretResolver
{
    public function resolve(string $reference): string
    {
        if (! preg_match('/^env:(OPSHUB_TELEGRAM_[A-Z0-9_]{3,100})$/', $reference, $m)) {
            throw new LogicException('Unsupported Telegram secret reference.');
        }
        $secret = Env::get($m[1]);
        if (! is_string($secret) || trim($secret) === '') {
            throw new LogicException('Telegram secret reference is not provisioned.');
        }

        return $secret;
    }
}
