<?php

namespace App\Application\TelegramNotifications;

use App\Jobs\SendTelegramDelivery;
use App\Models\Organization;
use App\Models\TelegramBinding;
use App\Models\TelegramBot;
use App\Models\TelegramDelivery;
use App\Models\TelegramUpdateReceipt;

class TelegramPrivateReply
{
    public function write(TelegramUpdateReceipt $receipt, array $payload, string $text, ?TelegramBinding $binding = null, array $projectIds = [], array $context = []): void
    {
        if ($payload['chat_type'] !== 'private' && ! $binding) {
            return;
        }
        $chatId = $binding?->chat_id ?? $payload['chat_id'];
        $org = Organization::findOrFail($receipt->organization_id);
        $event = app(OutboxWriter::class)->record($org, 'telegram.private_reply', 'telegram_update_receipt', $receipt->id, 1, ['impacted_project_ids' => $projectIds, 'receipt_id' => $receipt->id, ...$context]);
        $event->update(['status' => 'materialized', 'dispatched_at' => now('UTC')]);
        $parent = null;
        foreach (app(TelegramMessages::class)->segment($text) as $index => $part) {
            $delivery = TelegramDelivery::create(['organization_id' => $org->id, 'telegram_bot_id' => $receipt->telegram_bot_id, 'binding_id' => $binding?->id, 'receipt_id' => $receipt->id, 'outbox_event_id' => $event->id, 'recipient_reference' => 'private:'.$chatId, 'notification_kind' => $binding ? 'private_reply' : 'binding_notice', 'chunk_index' => $index, 'parent_delivery_id' => $parent, 'project_ids' => $projectIds, 'text' => $part, 'severity' => 'info', 'priority' => 2, 'available_at' => now('UTC')]);
            $delivery->update(['identity_version' => TelegramBot::findOrFail($receipt->telegram_bot_id)->identity_version]);
            $parent = $delivery->id;
            SendTelegramDelivery::dispatch($delivery->id)->onConnection('database')->onQueue(config('opshub.queue.notification'));
        }
    }
}
