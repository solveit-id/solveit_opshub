<?php

namespace App\Infrastructure\Telegram;

use App\Infrastructure\Security\LiveConnectorGate;
use App\Infrastructure\Security\OutboundTargetValidator;
use App\Models\TelegramBot;

class NativeTelegramTransport implements TelegramTransport
{
    public function __construct(private readonly TelegramSecretResolver $secrets, private readonly OutboundTargetValidator $targets) {}

    public function request(TelegramBot $bot, string $method, array $payload = []): TelegramResult
    {
        if (! config('opshub.live_connectors_enabled')) {
            return new TelegramResult('unavailable', 'LIVE_DISABLED');
        }
        app(LiveConnectorGate::class)->assertEnabled();
        if (! in_array($method, ['getMe', 'sendMessage', 'answerCallbackQuery', 'setWebhook'], true)) {
            return new TelegramResult('failed', 'METHOD_NOT_ALLOWED');
        }
        try {
            if ($method === 'sendMessage' && (! is_string($payload['text'] ?? null) || mb_strlen($payload['text']) === 0 || intdiv(strlen(mb_convert_encoding($payload['text'], 'UTF-16LE', 'UTF-8')), 2) > 3500 || ! preg_match('/^-?[1-9]\d{0,18}$/', (string) ($payload['chat_id'] ?? '')))) {
                return new TelegramResult('failed', 'PAYLOAD_INVALID');
            }
            $token = $this->secrets->resolve($bot->token_secret_reference);
            if (! preg_match('/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $token)) {
                return new TelegramResult('failed', 'TOKEN_FORMAT_INVALID');
            }
            // Resolve/validate without the token-bearing path; no HTTP facade events, URL logs or curl error text.
            $ip = $this->targets->addresses('https://api.telegram.org/')[0];
            $handle = curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
            $body = '';
            $headers = 0;
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR), CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => ['api.telegram.org:443:'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)], CURLOPT_TIMEOUT_MS => 10000, CURLOPT_CONNECTTIMEOUT_MS => 3000, CURLOPT_VERBOSE => false,
                CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 65536) {
                        return 0;
                    } $body .= $chunk;

                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => function ($curl, string $chunk) use (&$headers): int {
                    $headers += strlen($chunk);

                    return $headers > 16384 ? 0 : strlen($chunk);
                },
            ]);
            $ok = curl_exec($handle);
            $info = curl_getinfo($handle);
            curl_close($handle);
            if ($ok === false) {
                return new TelegramResult(($info['request_size'] ?? 0) > 0 ? 'unknown' : 'retrying', ($info['request_size'] ?? 0) > 0 ? 'DELIVERY_UNCERTAIN' : 'NETWORK_BEFORE_SEND');
            }
            if (! isset($info['primary_ip']) || inet_pton($info['primary_ip']) !== inet_pton($ip) || ! $this->targets->isPublicAddress($info['primary_ip'])) {
                return new TelegramResult('unknown', 'PEER_MISMATCH');
            }

            return $this->interpret($method, (int) $info['http_code'], json_decode($body, true));
        } catch (\Throwable) {
            return new TelegramResult('failed', 'TRANSPORT_CONFIGURATION_ERROR');
        }
    }

    public function interpret(string $method, int $http, mixed $body): TelegramResult
    {
        $code = is_array($body) ? (int) ($body['error_code'] ?? $http) : $http;
        if ($code === 429) {
            return new TelegramResult('retrying', 'RATE_LIMITED', $http, retryAfter: max(1, min(86400, (int) ($body['parameters']['retry_after'] ?? 10))));
        }
        if ($code >= 500) {
            return new TelegramResult('retrying', 'PROVIDER_UNAVAILABLE', $http);
        }
        if ($code === 401 || $code === 403 || $code === 400) {
            return new TelegramResult('failed', match ($code) {
                401 => 'TOKEN_INVALID', 403 => 'DESTINATION_FORBIDDEN', default => 'PAYLOAD_INVALID'
            }, $http);
        }
        if (! is_array($body)) {
            return new TelegramResult('unknown', 'INVALID_PROVIDER_RESPONSE', $http);
        }
        if ($http !== 200 || ($body['ok'] ?? false) !== true) {
            return new TelegramResult('failed', 'PROVIDER_REJECTED', $http);
        }
        if ($method === 'sendMessage') {
            $id = $body['result']['message_id'] ?? null;

            return is_int($id) && $id > 0 ? new TelegramResult('sent', 'ACCEPTED', $http, messageId: (string) $id) : new TelegramResult('unknown', 'MISSING_MESSAGE_ID', $http);
        }
        if ($method === 'getMe') {
            $id = $body['result']['id'] ?? null;

            return is_int($id) && $id > 0 && ($body['result']['is_bot'] ?? false) === true ? new TelegramResult('accepted', 'BOT_VERIFIED', $http, botId: (string) $id) : new TelegramResult('failed', 'BOT_IDENTITY_INVALID', $http);
        }

        return new TelegramResult('accepted', 'ACCEPTED', $http);
    }
}
