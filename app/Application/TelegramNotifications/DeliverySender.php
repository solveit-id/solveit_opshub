<?php

namespace App\Application\TelegramNotifications;

use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\IdentityAccess\ProjectAccess;
use App\Infrastructure\Telegram\NativeTelegramTransport;
use App\Infrastructure\Telegram\TelegramResult;
use App\Infrastructure\Telegram\TelegramTransport;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TelegramBot;
use App\Models\TelegramDelivery;
use App\Models\TelegramDestination;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeliverySender
{
    public function send(int $id, ?CarbonImmutable $now = null): ?TelegramDelivery
    {
        $now ??= CarbonImmutable::now('UTC');
        $transport = app(TelegramTransport::class);
        $delivery = TelegramDelivery::findOrFail($id);
        if ($transport instanceof NativeTelegramTransport && ! config('opshub.live_connectors_enabled')) {
            if (in_array($delivery->state, ['pending', 'retrying'], true)) {
                $delivery->update(['result_code' => 'LIVE_DISABLED']);
            }

            return $delivery->fresh();
        }
        $claimed = DB::transaction(function () use ($delivery, $now) {
            $org = Organization::whereKey($delivery->organization_id)->lockForUpdate()->firstOrFail();
            $bot = TelegramBot::whereKey($delivery->telegram_bot_id)->lockForUpdate()->firstOrFail();
            $d = TelegramDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if (! in_array($d->state, ['pending', 'retrying'], true) || $d->available_at->greaterThan($now)) {
                return null;
            }
            if ($d->attempts >= 5 || ($d->first_attempt_at && $d->first_attempt_at->addMinutes(15)->lessThanOrEqualTo($now))) {
                $d->update(['state' => 'failed', 'result_code' => 'RETRY_EXHAUSTED']);

                return null;
            }
            $dest = $d->telegram_destination_id ? TelegramDestination::forOrganization($org)->find($d->telegram_destination_id) : null;
            if (! $org->is_active || ! $bot->enabled || ! $bot->identity_verified_at || ($bot->identity_fake && ! app()->environment('testing')) || ! $dest?->enabled) {
                $d->update(['state' => 'cancelled', 'result_code' => 'CONFIGURATION_DISABLED']);

                return null;
            }
            $scope = $dest->all_projects ? Project::forOrganization($org)->pluck('id')->all() : $dest->project_ids;
            if (array_diff($d->project_ids, $scope) !== []) {
                $d->update(['state' => 'cancelled', 'result_code' => 'DESTINATION_SCOPE_CHANGED']);

                return null;
            }
            if ($dest->chat_type === 'private') {
                $member = User::find($dest->member_user_id);
                if (! $member || ! app(OrganizationAuthorizationService::class)->membership($member, $org) || (! app(ProjectAccess::class)->owner($member, $org) && app(ProjectAccess::class)->query($member, $org)->whereIn('id', $d->project_ids)->count() !== count($d->project_ids))) {
                    $d->update(['state' => 'cancelled', 'result_code' => 'RECIPIENT_ACCESS_CHANGED']);

                    return null;
                }
            }
            if ($d->parent_delivery_id && TelegramDelivery::findOrFail($d->parent_delivery_id)->state !== 'sent') {
                return null;
            }
            $at = app(TelegramRateLimiter::class)->available($bot, $d, $now);
            if ($at->greaterThan($now)) {
                $d->update(['available_at' => $at, 'result_code' => 'LOCAL_RATE_LIMIT']);

                return null;
            }
            $lease = (string) Str::uuid();
            $d->update(['state' => 'sending', 'lease_owner' => $lease, 'lease_version' => $d->lease_version + 1, 'lease_until' => $now->addSeconds(30), 'first_attempt_at' => $d->first_attempt_at ?? $now, 'last_attempt_at' => $now, 'attempts' => $d->attempts + 1]);
            DB::table('telegram_delivery_attempts')->insert(['telegram_bot_id' => $bot->id, 'telegram_delivery_id' => $d->id, 'recipient_reference' => $d->recipient_reference, 'attempt' => $d->attempts, 'started_at' => $now->format('Y-m-d H:i:s.u')]);

            return [$d, $bot, $dest, $lease];
        });
        if (! $claimed) {
            return $delivery->fresh();
        }
        [$d, $bot, $dest, $lease] = $claimed;
        $payload = ['chat_id' => $dest->chat_id, 'text' => $d->text, 'link_preview_options' => ['is_disabled' => true]];
        if ($d->reply_markup) {
            $payload['reply_markup'] = $d->reply_markup;
        }
        try {
            $result = $transport->request($bot, 'sendMessage', $payload);
        } catch (\Throwable) {
            $result = new TelegramResult('unknown', 'DELIVERY_UNCERTAIN');
        }

        return DB::transaction(function () use ($d, $lease, $result, $now) {
            $current = TelegramDelivery::whereKey($d->id)->lockForUpdate()->firstOrFail();
            if ($current->state !== 'sending' || $current->lease_owner !== $lease || $current->lease_version !== $d->lease_version) {
                return $current;
            }
            $state = $result->outcome === 'sent' && $result->messageId ? 'sent' : (in_array($result->outcome, ['failed', 'unknown', 'retrying'], true) ? $result->outcome : 'unknown');
            if ($result->fake && ! app()->environment('testing')) {
                $state = 'failed';
            }
            $current->update(['state' => $state, 'http_status' => $result->httpStatus, 'result_code' => $result->code, 'message_id' => $state === 'sent' ? $result->messageId : null, 'sent_at' => $state === 'sent' ? $now : null, 'available_at' => $now->addSeconds($result->retryAfter ?? [10, 30, 90, 300][min($d->attempts - 1, 3)]), 'fake' => $result->fake, 'lease_owner' => null, 'lease_until' => null]);
            DB::table('telegram_delivery_attempts')->where('telegram_delivery_id', $d->id)->where('attempt', $d->attempts)->update(['finished_at' => $now->format('Y-m-d H:i:s.u'), 'outcome' => $state, 'result_code' => $result->code, 'http_status' => $result->httpStatus, 'message_id' => $result->messageId, 'fake' => $result->fake]);

            return $current->fresh();
        });
    }
}
