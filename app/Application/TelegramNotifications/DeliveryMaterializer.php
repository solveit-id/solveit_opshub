<?php

namespace App\Application\TelegramNotifications;

use App\Models\IncidentNotificationHistory;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\TelegramBot;
use App\Models\TelegramDelivery;
use App\Models\TelegramDestination;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DeliveryMaterializer
{
    public const EVENTS = ['telegram.notification.requested', 'telegram.test_requested', 'incident.opened', 'incident.stability_warning', 'incident.resolved', 'renewal.reminder', 'renewal.verification_required', 'renewal.verified', 'connector.failed', 'backup.issue.opened'];

    public function materialize(Organization $org, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($org, $now) {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $bot = TelegramBot::forOrganization($org)->where('enabled', true)->whereNotNull('identity_verified_at')->first();
            if (! $org->is_active || ! $bot || ($bot->identity_fake && ! app()->environment('testing'))) {
                return 0;
            }
            $count = 0;
            $events = OutboxEvent::forOrganization($org)->where('status', 'pending')->whereIn('event_type', self::EVENTS)->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', $now))->orderBy('id')->limit(100)->lockForUpdate()->get();
            foreach ($events as $event) {
                $targets = TelegramDestination::forOrganization($org)->where('telegram_bot_id', $bot->id)->where('enabled', true)->get()->filter(function ($dest) use ($event) {
                    if ($event->event_type === 'incident.resolved' && ! app(DeliveryReconciler::class)->hasDown($event->aggregate_id, 'destination:'.$dest->id)) {
                        return false;
                    }
                    if ($event->event_type === 'telegram.test_requested') {
                        return $dest->id === ($event->payload['destination_id'] ?? null);
                    }
                    if (($event->payload['route'] ?? '') === 'owner' && ! $dest->owner_route) {
                        return false;
                    }
                    if (! in_array($event->payload['severity'] ?? 'info', $dest->severities, true)) {
                        return false;
                    }
                    $ids = $event->payload['impacted_project_ids'] ?? [];

                    return $ids === [] ? $dest->owner_route : ($dest->all_projects || array_intersect($ids, $dest->project_ids) !== []);
                });
                if ($targets->isEmpty()) {
                    if ($event->event_type === 'incident.resolved' && ! IncidentNotificationHistory::where('incident_id', $event->aggregate_id)->get()->contains(fn ($h) => app(DeliveryReconciler::class)->hasDown($event->aggregate_id, $h->recipient_reference)) && ! TelegramDelivery::whereIn('outbox_event_id', OutboxEvent::where('aggregate_type', 'incident')->where('aggregate_id', $event->aggregate_id)->whereIn('event_type', ['incident.opened', 'incident.stability_warning'])->select('id'))->whereIn('state', ['sending', 'unknown', 'retrying'])->exists()) {
                        $event->update(['status' => 'superseded']);
                    }

                    continue;
                }
                foreach ($targets as $dest) {
                    $parent = null;
                    foreach (app(TelegramMessages::class)->render($event, $dest) as $index => $message) {
                        $delivery = TelegramDelivery::firstOrCreate(['outbox_event_id' => $event->id, 'recipient_reference' => 'destination:'.$dest->id, 'notification_kind' => $message['kind'], 'chunk_index' => $index, 'revision' => 1], [
                            'organization_id' => $org->id, 'telegram_bot_id' => $bot->id, 'telegram_destination_id' => $dest->id, 'parent_delivery_id' => $parent,
                            'project_ids' => $message['project_ids'], 'text' => $message['text'], 'reply_markup' => $message['reply_markup'], 'severity' => $event->payload['severity'] ?? 'info', 'identity_version' => $bot->identity_version,
                            'priority' => array_search($event->payload['severity'] ?? 'info', ['critical', 'warning', 'info']), 'available_at' => $now, 'fake' => $bot->identity_fake,
                        ]);
                        $parent = $delivery->id;
                        $count += $delivery->wasRecentlyCreated ? 1 : 0;
                    }
                }
                $event->update(['status' => 'materialized', 'dispatched_at' => $now, 'attempts' => $event->attempts + 1]);
            }
            app(DeliveryCoalescer::class)->coalesce($org, $now);

            return $count;
        });
    }
}
