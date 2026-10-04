<?php

namespace App\Application\TelegramNotifications;

use App\Infrastructure\Telegram\NativeTelegramTransport;
use App\Infrastructure\Telegram\TelegramTransport;
use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SendTelegramDelivery;
use App\Models\Organization;
use App\Models\TelegramBot;
use App\Models\TelegramDelivery;
use App\Models\TelegramIntegrationFinding;
use App\Models\TelegramUpdateReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class NotificationDispatcher
{
    public function tick(Organization $org, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($org, $now) {
            $org = Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $bot = TelegramBot::forOrganization($org)->lockForUpdate()->first();
            if (! $org->is_active || ! $bot) {
                return 0;
            }
            $backlog = TelegramUpdateReceipt::forOrganization($org)->where('status', 'pending')->where('received_at', '<=', $now->subMinutes(3))->get();
            if ($backlog->isNotEmpty()) {
                app(NotificationFindings::class)->record($bot, 'bot:'.$bot->id, 'WEBHOOK_BACKLOG', $now);
                foreach ($backlog->take(100) as $r) {
                    ProcessTelegramUpdate::dispatch($r->id)->onConnection('database')->onQueue(config('opshub.queue.notification'));
                }
            } else {
                TelegramIntegrationFinding::forOrganization($org)->where('telegram_bot_id', $bot->id)->where('code', 'WEBHOOK_BACKLOG')->where('state', '!=', 'resolved')->update(['state' => 'resolved', 'resolved_at' => $now]);
            }
            foreach (TelegramDelivery::forOrganization($org)->where('state', 'sending')->where('lease_until', '<=', $now)->lockForUpdate()->get() as $d) {
                $d->update(['state' => 'unknown', 'uncertain' => true, 'result_code' => 'LEASE_EXPIRED_UNCERTAIN', 'lease_owner' => null, 'lease_until' => null, 'lease_version' => $d->lease_version + 1, 'queued_at' => null, 'available_at' => $now->addSeconds(10)]);
                DB::table('telegram_delivery_attempts')->where('telegram_delivery_id', $d->id)->where('attempt', $d->attempts)->whereNull('finished_at')->update(['finished_at' => $now->format('Y-m-d H:i:s.u'), 'outcome' => 'unknown', 'result_code' => 'LEASE_EXPIRED_UNCERTAIN']);
                app(NotificationFindings::class)->record($bot, $d->recipient_reference, 'DELIVERY_UNCERTAIN', $now);
            }
            TelegramDelivery::forOrganization($org)->where('state', 'unknown')->where('attempts', '<', 2)->where('available_at', '<=', $now)->where('first_attempt_at', '>', $now->subMinutes(15))->update(['state' => 'retrying', 'result_code' => 'UNCERTAIN_BOUNDED_RETRY']);
            if (! $bot->enabled || ! $bot->identity_verified_at || ($bot->identity_fake && ! app()->environment('testing'))) {
                return 0;
            }
            app(DeliveryMaterializer::class)->materialize($org, $now);
            app(TelegramDigest::class)->schedule($org, $bot, $now);
            $nativeDisabled = app(TelegramTransport::class) instanceof NativeTelegramTransport && ! config('opshub.live_connectors_enabled');
            $count = 0;
            foreach (TelegramDelivery::forOrganization($org)->whereIn('state', ['pending', 'retrying'])->where('available_at', '<=', $now)->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<=', $now->subMinute()))->orderBy('priority')->orderBy('id')->limit(100)->lockForUpdate()->get() as $d) {
                // Current-state/quiet reconciliation is useful even while native sending is disabled.
                try {
                    if (! app(DeliveryReconciler::class)->before($d, $bot, $org, $now)) {
                        continue;
                    }
                } catch (AuthorizationException|HttpExceptionInterface) {
                    $d->update(['state' => 'cancelled', 'result_code' => 'CURRENT_ACCESS_DENIED']);

                    continue;
                }
                if (app(TelegramQuietHours::class)->defer($d, $bot, $now)) {
                    continue;
                }
                if ($nativeDisabled) {
                    $d->update(['result_code' => 'LIVE_DISABLED']);

                    continue;
                }
                $d->update(['queued_at' => $now]);
                SendTelegramDelivery::dispatch($d->id)->onConnection('database')->onQueue(config('opshub.queue.'.($d->severity === 'critical' ? 'critical' : 'notification')));
                $count++;
            }

            return $count;
        });
    }
}
