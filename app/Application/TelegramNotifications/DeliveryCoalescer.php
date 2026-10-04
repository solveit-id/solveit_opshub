<?php

namespace App\Application\TelegramNotifications;

use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\TelegramDelivery;
use Carbon\CarbonImmutable;

class DeliveryCoalescer
{
    public function coalesce(Organization $org, CarbonImmutable $now): void
    {
        $groups = TelegramDelivery::forOrganization($org)->where('state', 'pending')->where('notification_kind', 'internal')->whereNotIn('outbox_event_id', OutboxEvent::where('event_type', 'telegram.test_requested')->select('id'))->where('created_at', '>=', $now->subMinutes(2))->whereNull('parent_delivery_id')->get()->groupBy(fn ($d) => $d->recipient_reference.'|'.$d->severity);
        foreach ($groups as $items) {
            if ($items->count() < 5) {
                continue;
            }
            $events = OutboxEvent::whereIn('id', $items->pluck('outbox_event_id'))->get();
            $first = $items->first();
            $ref = $first->recipient_reference;
            $priority = $items->min('priority');
            $summary = ($first->fake ? "[FAKE TESTING]\n" : '').'RINGKASAN '.strtoupper(['critical', 'warning', 'info'][$priority])."\n".$events->count()." event operasional; detail/evidence tetap di dashboard.\n".$events->take(10)->map(fn ($e) => 'Event '.$e->event_id.' · '.$e->event_type.' · '.$e->aggregate_type.' #'.$e->aggregate_id)->implode("\n")."\n".($events->count() > 10 ? '+'.($events->count() - 10).' event lainnya di dashboard.' : '')."\nWaktu: ".$now->setTimezone('Asia/Jakarta')->format('d-m-Y H:i')." WIB\nAction owner/PIC: tinjau assignment masing-masing event di dashboard.\nTindakan: tinjau semua incident/follow-up prioritas.\nDashboard monitoring: ".rtrim(config('app.url'), '/').'/organizations/'.$org->id.'/overview'."\nDashboard renewal: ".app(TelegramMessages::class)->link($org);
            $batch = TelegramDelivery::firstOrCreate(['outbox_event_id' => $first->outbox_event_id, 'recipient_reference' => $ref, 'notification_kind' => 'coalesced', 'chunk_index' => 0, 'revision' => 1], ['organization_id' => $org->id, 'telegram_bot_id' => $first->telegram_bot_id, 'telegram_destination_id' => $first->telegram_destination_id, 'event_ids' => $events->pluck('id')->all(), 'project_ids' => $items->pluck('project_ids')->flatten()->unique()->values()->all(), 'text' => $summary, 'severity' => ['critical', 'warning', 'info'][$priority], 'priority' => $priority, 'available_at' => $now, 'fake' => $first->fake, 'identity_version' => $first->identity_version]);
            TelegramDelivery::whereIn('outbox_event_id', $events->pluck('id'))->where('recipient_reference', $ref)->where('state', 'pending')->where('id', '!=', $batch->id)->update(['state' => 'superseded', 'result_code' => 'COALESCED_VIEW_DASHBOARD', 'coalesced_into_id' => $batch->id]);
        }
    }
}
