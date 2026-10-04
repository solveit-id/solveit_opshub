<?php

namespace App\Application\TelegramNotifications;

use App\Infrastructure\Telegram\TelegramSecretResolver;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\Organization;
use App\Models\TelegramBot;
use App\Models\TelegramUpdateReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TelegramWebhook
{
    public function receive(TelegramBot $bot, Request $request): TelegramUpdateReceipt
    {
        $header = $request->header('X-Telegram-Bot-Api-Secret-Token');
        try {
            $secret = app(TelegramSecretResolver::class)->resolve($bot->webhook_secret_reference);
        } catch (\Throwable) {
            abort(503, 'Webhook secret unavailable.');
        }
        abort_unless(is_string($header) && preg_match('/^[A-Za-z0-9_-]{1,256}$/', $secret) && hash_equals($secret, $header), 403);
        abort_unless($bot->enabled && $bot->identity_verified_at && (! $bot->identity_fake || app()->environment('testing')) && Organization::findOrFail($bot->organization_id)->is_active, 403);
        abort_unless(strlen($request->getContent()) <= 65536 && $request->isJson(), 422);
        $data = $request->json()->all();
        abort_unless(is_int($data['update_id'] ?? null) && $data['update_id'] >= 0, 422);
        $payload = $this->normalize($data);

        return DB::transaction(function () use ($bot, $data, $payload) {
            TelegramBot::whereKey($bot->id)->lockForUpdate()->firstOrFail();
            $receipt = TelegramUpdateReceipt::firstOrCreate(['telegram_bot_id' => $bot->id, 'update_id' => $data['update_id']], ['organization_id' => $bot->organization_id, 'identity_version' => $bot->identity_version, 'encrypted_payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'received_at' => now('UTC')]);
            if ($receipt->wasRecentlyCreated) {
                ProcessTelegramUpdate::dispatch($receipt->id)->onConnection('database')->onQueue(config('opshub.queue.notification'));
            }

            return $receipt;
        });
    }

    private function normalize(array $data): array
    {
        $callback = $data['callback_query'] ?? null;
        $message = $callback['message'] ?? $data['message'] ?? null;
        $from = $callback['from'] ?? $message['from'] ?? null;
        abort_unless(is_array($message) && is_array($from) && is_int($from['id'] ?? null) && $from['id'] > 0 && ($from['is_bot'] ?? true) === false, 422);
        $chat = $message['chat'] ?? null;
        abort_unless(is_array($chat) && is_int($chat['id'] ?? null) && $chat['id'] !== 0 && in_array($chat['type'] ?? '', ['private', 'group', 'supergroup'], true), 422);
        abort_unless($chat['type'] !== 'private' || $chat['id'] === $from['id'], 422);
        $safe = ['telegram_user_id' => (string) $from['id'], 'chat_id' => (string) $chat['id'], 'chat_type' => $chat['type']];
        if ($callback) {
            abort_unless(is_string($callback['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $callback['id']) && is_string($callback['data'] ?? null) && preg_match('/^[a-f0-9]{48}$/', $callback['data']), 422);

            return [...$safe, 'kind' => 'callback', 'query_id' => $callback['id'], 'reference_hash' => hash('sha256', $callback['data'])];
        }
        $text = $message['text'] ?? null;
        abort_unless(is_string($text) && strlen($text) <= 256, 422);
        if (preg_match('/^\/start ([a-f0-9]{48})$/', $text, $match)) {
            return [...$safe, 'kind' => 'binding', 'token_hash' => hash('sha256', $match[1])];
        }
        if (preg_match('/^\/template ([1-9]\d{0,18})$/', $text, $match)) {
            return [...$safe, 'kind' => 'command', 'command' => 'template', 'followup_id' => (int) $match[1]];
        }
        if (preg_match('/^\/(start|help|today|incidents)(?:@[A-Za-z0-9_]{5,32})?$/', $text, $match)) {
            return [...$safe, 'kind' => 'command', 'command' => $match[1]];
        }

        return [...$safe, 'kind' => 'command', 'command' => 'help']; // Arbitrary user text is discarded, never logged/stored.
    }
}
