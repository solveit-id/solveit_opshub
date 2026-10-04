<?php

namespace App\Application\TelegramNotifications;

use App\Infrastructure\Telegram\NativeTelegramTransport;
use App\Infrastructure\Telegram\TelegramResult;
use App\Infrastructure\Telegram\TelegramTransport;
use App\Models\Organization;
use App\Models\TelegramBot;
use App\Models\TelegramDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DeliverySender
{
    public function send(int $id, ?CarbonImmutable $now = null): ?TelegramDelivery
    {
        $now ??= CarbonImmutable::now('UTC');
        $transport = app(TelegramTransport::class);
        $delivery = TelegramDelivery::findOrFail($id);
        $nativeDisabled = $transport instanceof NativeTelegramTransport && ! config('opshub.live_connectors_enabled');
        $claimed = DB::transaction(function () use ($delivery, $now, $nativeDisabled) {
            $org = Organization::whereKey($delivery->organization_id)->lockForUpdate()->firstOrFail();
            $bot = TelegramBot::whereKey($delivery->telegram_bot_id)->lockForUpdate()->firstOrFail();
            $d = TelegramDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $d->update(['queued_at' => null]);
            if (! in_array($d->state, ['pending', 'retrying'], true) || $d->available_at->greaterThan($now)) {
                return null;
            }
            if (! $org->is_active || ! $bot->enabled || ! $bot->identity_verified_at) {
                $d->update(['state' => 'cancelled', 'result_code' => 'CONFIGURATION_DISABLED']);

                return null;
            }
            if ($d->attempts >= 5 || ($d->first_attempt_at && $d->first_attempt_at->addMinutes(15)->lessThanOrEqualTo($now))) {
                $d->update(['state' => 'failed', 'result_code' => 'RETRY_EXHAUSTED']);
                app(NotificationFindings::class)->outcome($d);

                return null;
            }
            try {
                if (! app(DeliveryReconciler::class)->before($d, $bot, $org, $now)) {
                    return null;
                }
            } catch (AuthorizationException|HttpExceptionInterface) {
                $d->update(['state' => 'cancelled', 'result_code' => 'CURRENT_ACCESS_DENIED']);

                return null;
            }
            $recipient = app(DeliveryRecipients::class)->resolve($d, $bot, $org);
            if (! $recipient) {
                $d->update(['state' => 'cancelled', 'result_code' => 'RECIPIENT_ACCESS_CHANGED']);

                return null;
            }
            if ($d->parent_delivery_id && TelegramDelivery::findOrFail($d->parent_delivery_id)->state !== 'sent') {
                $parent = TelegramDelivery::findOrFail($d->parent_delivery_id);
                if (in_array($parent->state, ['cancelled', 'superseded', 'failed'], true) || ($parent->state === 'unknown' && $parent->attempts >= 2)) {
                    $d->update(['state' => 'cancelled', 'result_code' => 'PARENT_NOT_DELIVERED']);
                }

                return null;
            }
            if (app(TelegramQuietHours::class)->defer($d, $bot, $now)) {
                return null;
            }
            if ($nativeDisabled) {
                $d->update(['result_code' => 'LIVE_DISABLED']);

                return null;
            }
            $at = app(TelegramRateLimiter::class)->available($bot, $d, $now, $recipient);
            if ($at->greaterThan($now)) {
                $d->update(['available_at' => $at, 'result_code' => 'LOCAL_RATE_LIMIT']);

                return null;
            }
            $lease = (string) Str::uuid();
            $d->update(['state' => 'sending', 'lease_owner' => $lease, 'lease_version' => $d->lease_version + 1, 'lease_until' => $now->addSeconds(30), 'first_attempt_at' => $d->first_attempt_at ?? $now, 'last_attempt_at' => $now, 'attempts' => $d->attempts + 1]);
            DB::table('telegram_delivery_attempts')->insert(['telegram_bot_id' => $bot->id, 'telegram_delivery_id' => $d->id, 'recipient_reference' => $d->recipient_reference, 'chat_rate_key' => 'chat:'.$recipient['chat_id'], 'attempt' => $d->attempts, 'started_at' => $now->format('Y-m-d H:i:s.u')]);

            return [$d, $bot, $recipient, $lease];
        });
        if (! $claimed) {
            return $delivery->fresh();
        }
        [$d, $bot, $recipient, $lease] = $claimed;
        $payload = ['chat_id' => $recipient['chat_id'], 'text' => $d->text, 'link_preview_options' => ['is_disabled' => true]];
        if ($d->reply_markup) {
            $payload['reply_markup'] = $d->reply_markup;
        }
        try {
            $result = $transport->request($bot, 'sendMessage', $payload);
        } catch (\Throwable) {
            $result = new TelegramResult('unknown', 'DELIVERY_UNCERTAIN');
        }

        return DB::transaction(function () use ($d, $lease, $result, $now) {
            Organization::whereKey($d->organization_id)->lockForUpdate()->firstOrFail();
            TelegramBot::whereKey($d->telegram_bot_id)->lockForUpdate()->firstOrFail();
            $current = TelegramDelivery::whereKey($d->id)->lockForUpdate()->firstOrFail();
            if ($current->state !== 'sending' || $current->lease_owner !== $lease || $current->lease_version !== $d->lease_version) {
                return $current;
            }
            $state = $result->outcome === 'sent' && $result->messageId ? 'sent' : (in_array($result->outcome, ['failed', 'unknown', 'retrying'], true) ? $result->outcome : 'unknown');
            if ($result->fake && ! app()->environment('testing')) {
                $state = 'failed';
            }
            $code = $result->code;
            if ($state === 'retrying' && $d->attempts >= 5) {
                $state = 'failed';
                $code = 'RETRY_EXHAUSTED';
            }
            $backoff = ($result->retryAfter ?? [10, 30, 90, 300][min($d->attempts - 1, 3)]) + (($d->id + $d->attempts) % 4);
            $current->update(['state' => $state, 'http_status' => $result->httpStatus, 'result_code' => $code, 'message_id' => $state === 'sent' ? $result->messageId : null, 'sent_at' => $state === 'sent' ? $now : null, 'available_at' => $now->addSeconds($backoff), 'uncertain' => $current->uncertain || $state === 'unknown', 'fake' => $result->fake, 'lease_owner' => null, 'lease_until' => null]);
            DB::table('telegram_delivery_attempts')->where('telegram_delivery_id', $d->id)->where('attempt', $d->attempts)->update(['finished_at' => $now->format('Y-m-d H:i:s.u'), 'outcome' => $state, 'result_code' => $result->code, 'http_status' => $result->httpStatus, 'message_id' => $result->messageId, 'fake' => $result->fake]);
            app(NotificationHistory::class)->sent($current);
            app(NotificationFindings::class)->outcome($current);

            return $current->fresh();
        });
    }
}
