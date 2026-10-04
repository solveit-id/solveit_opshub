<?php

namespace App\Application\TelegramNotifications;

use App\Models\TelegramBot;
use App\Models\TelegramDelivery;
use App\Models\TelegramDestination;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TelegramRateLimiter
{
    // Caller holds the bot lock through attempt reservation, before releasing it for I/O.
    public function available(TelegramBot $bot, TelegramDelivery $delivery, CarbonImmutable $now, ?array $recipient = null): CarbonImmutable
    {
        $at = $now;
        $global = DB::table('telegram_delivery_attempts')->where('telegram_bot_id', $bot->id)->where('started_at', '>', $now->subSecond()->format('Y-m-d H:i:s.u'))->orderBy('started_at')->get();
        if ($global->count() >= 20) {
            $at = $at->max(CarbonImmutable::parse($global->first()->started_at, 'UTC')->addSecond());
        }
        $type = $delivery->telegram_destination_id ? TelegramDestination::findOrFail($delivery->telegram_destination_id)->chat_type : 'private';
        $chatId = $recipient['chat_id'] ?? ($delivery->telegram_destination_id ? TelegramDestination::findOrFail($delivery->telegram_destination_id)->chat_id : null);
        $attempts = DB::table('telegram_delivery_attempts')->where('telegram_bot_id', $bot->id)->where(fn ($q) => $q->where('recipient_reference', $delivery->recipient_reference)->when($chatId, fn ($q) => $q->orWhere('chat_rate_key', 'chat:'.$chatId)))->where('started_at', '>', $now->subSeconds($type === 'private' ? 1 : 60)->format('Y-m-d H:i:s.u'))->orderBy('started_at')->get();
        if ($attempts->count() >= ($type === 'private' ? 1 : 15)) {
            $at = $at->max(CarbonImmutable::parse($attempts->first()->started_at, 'UTC')->addSeconds($type === 'private' ? 1 : 60));
        }

        return $at;
    }
}
