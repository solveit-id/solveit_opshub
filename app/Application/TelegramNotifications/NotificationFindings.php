<?php

namespace App\Application\TelegramNotifications;

use App\Models\OutboxEvent;
use App\Models\TelegramBinding;
use App\Models\TelegramBot;
use App\Models\TelegramDelivery;
use App\Models\TelegramDestination;
use App\Models\TelegramIntegrationFinding;
use Carbon\CarbonImmutable;

class NotificationFindings
{
    public function record(TelegramBot $bot, string $recipient, string $code, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now('UTC');
        $actions = ['TOKEN_INVALID' => 'Owner: rotasi/review token reference, test identity ulang, enable bot dan test destination.', 'DESTINATION_FORBIDDEN' => 'Owner: periksa block/removal/anggota dan allowlist scope, enable destination lalu test eksplisit.', 'WEBHOOK_BACKLOG' => 'Owner: periksa notification worker, queue dan webhook receiver; proses receipt pending.', 'DELIVERY_UNCERTAIN' => 'Owner: pesan mungkin diterima; periksa chat/event ID dan histori sebelum mengirim ulang. Duplicate mungkin terjadi.', 'RETRY_EXHAUSTED' => 'Owner: periksa koneksi/Telegram/config, kemudian test eksplisit setelah perbaikan.', 'PAYLOAD_INVALID' => 'Owner: periksa payload/renderer dan test ulang; tidak ada retry permanen.'];
        $finding = TelegramIntegrationFinding::firstOrNew(['telegram_bot_id' => $bot->id, 'recipient_reference' => $recipient, 'code' => $code]);
        $finding->fill(['organization_id' => $bot->organization_id, 'action' => $actions[$code] ?? 'Owner: periksa konfigurasi notification dan evidence delivery di dashboard.', 'first_detected_at' => $finding->first_detected_at ?? $now, 'last_detected_at' => $now, 'version' => ($finding->version ?? 0) + 1]);
        if (! $finding->exists || $finding->state === 'resolved') {
            $finding->fill(['state' => 'open', 'resolved_at' => null, 'verified_delivery_id' => null, 'acknowledged_at' => null, 'acknowledged_by_user_id' => null]);
        }
        $finding->save();
    }

    public function outcome(TelegramDelivery $d): void
    {
        $bot = TelegramBot::whereKey($d->telegram_bot_id)->lockForUpdate()->firstOrFail();
        if ($d->state === 'unknown') {
            $this->record($bot, $d->recipient_reference, 'DELIVERY_UNCERTAIN');
        }
        if ($d->state === 'failed') {
            $this->record($bot, $d->result_code === 'TOKEN_INVALID' ? 'bot:'.$bot->id : $d->recipient_reference, $d->result_code);
            if ($d->result_code === 'TOKEN_INVALID') {
                $bot->update(['enabled' => false, 'identity_verified_at' => null, 'version' => $bot->version + 1]);
            }
            if ($d->result_code === 'DESTINATION_FORBIDDEN') {
                if ($d->telegram_destination_id) {
                    $dest = TelegramDestination::findOrFail($d->telegram_destination_id);
                    $dest->update(['enabled' => false, 'version' => $dest->version + 1]);
                }
                if ($d->binding_id) {
                    TelegramBinding::whereKey($d->binding_id)->update(['enabled' => false]);
                }
            }
        }
        if ($d->state === 'sent' && OutboxEvent::findOrFail($d->outbox_event_id)->event_type === 'telegram.test_requested') {
            TelegramIntegrationFinding::forOrganization($d->organization_id)->where('telegram_bot_id', $bot->id)->whereIn('recipient_reference', [$d->recipient_reference, 'bot:'.$bot->id])->whereIn('code', ['TOKEN_INVALID', 'DESTINATION_FORBIDDEN', 'RETRY_EXHAUSTED', 'PAYLOAD_INVALID', 'TRANSPORT_CONFIGURATION_ERROR'])->where('state', '!=', 'resolved')->update(['state' => 'resolved', 'resolved_at' => now('UTC'), 'verified_delivery_id' => $d->id]);
        }
    }
}
