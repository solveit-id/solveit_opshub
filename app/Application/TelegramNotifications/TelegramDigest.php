<?php

namespace App\Application\TelegramNotifications;

use App\Application\RenewalFollowups\Expiry;
use App\Application\RenewalFollowups\RenewalAccess;
use App\Models\ClientFollowup;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\TelegramBot;
use App\Models\TelegramDelivery;
use App\Models\TelegramDestination;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TelegramDigest
{
    public function schedule(Organization $org, TelegramBot $bot, CarbonImmutable $now): void
    {
        $local = $now->setTimezone($bot->settings['timezone']);
        if ($local->format('H:i') < $bot->settings['digest_time']) {
            return;
        }
        foreach (TelegramDestination::forOrganization($org)->where('telegram_bot_id', $bot->id)->where('enabled', true)->get() as $dest) {
            if (DB::table('telegram_digest_slots')->where('telegram_destination_id', $dest->id)->where('local_date', $local->toDateString())->exists()) {
                continue;
            }
            $held = TelegramDelivery::forOrganization($org)->where('telegram_destination_id', $dest->id)->whereIn('state', ['pending', 'retrying'])->where('result_code', 'QUIET_HOURS')->get();
            $scope = $dest->all_projects ? Project::forOrganization($org)->pluck('id')->all() : $dest->project_ids;
            $event = app(OutboxWriter::class)->record($org, 'telegram.digest', 'telegram_destination_digest', $dest->id, (int) $local->format('Ymd'), ['destination_id' => $dest->id, 'impacted_project_ids' => $scope, 'severity' => 'info', 'local_date' => $local->toDateString()]);
            $snapshot = $this->current($org, $bot, $dest, $event, $held->pluck('outbox_event_id')->unique()->values()->all(), $now);
            $first = null;
            $parent = null;
            foreach ($snapshot['parts'] as $index => $part) {
                $d = TelegramDelivery::create(['organization_id' => $org->id, 'telegram_bot_id' => $bot->id, 'telegram_destination_id' => $dest->id, 'outbox_event_id' => $event->id, 'recipient_reference' => 'destination:'.$dest->id, 'notification_kind' => 'digest', 'chunk_index' => $index, 'parent_delivery_id' => $parent, 'project_ids' => $scope, 'event_ids' => $held->pluck('outbox_event_id')->unique()->values()->all(), 'text' => $part, 'severity' => 'info', 'priority' => 2, 'available_at' => $now, 'identity_version' => $bot->identity_version, 'fake' => $bot->identity_fake, 'down_incident_ids' => $index === count($snapshot['parts']) - 1 ? $snapshot['down_ids'] : []]);
                $first ??= $d->id;
                $parent = $d->id;
            }
            TelegramDelivery::whereIn('id', $held->pluck('id'))->update(['state' => 'superseded', 'result_code' => 'QUIET_DIGEST', 'coalesced_into_id' => $first]);
            $event->update(['status' => 'materialized', 'dispatched_at' => $now]);
            DB::table('telegram_digest_slots')->insert(['organization_id' => $org->id, 'telegram_bot_id' => $bot->id, 'telegram_destination_id' => $dest->id, 'local_date' => $local->toDateString(), 'timezone' => $bot->settings['timezone'], 'outbox_event_id' => $event->id, 'created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s')]);
        }
    }

    public function current(Organization $org, TelegramBot $bot, TelegramDestination $dest, OutboxEvent $event, array $heldIds, CarbonImmutable $now): array
    {
        $scope = $dest->all_projects ? Project::forOrganization($org)->pluck('id')->all() : $dest->project_ids;
        $items = [];
        $shownDown = [];
        foreach (Incident::forOrganization($org)->whereNotIn('state', ['resolved', 'closed'])->where('severity', 'critical')->whereHas('projects', fn ($q) => $q->whereIn('projects.id', $scope))->get() as $i) {
            $items[] = ['rank' => 0, 'incident_id' => $i->id, 'text' => 'CRITICAL incident #'.$i->id.' · '.$i->state];
        }
        foreach (ClientFollowup::forOrganization($org)->whereNotIn('state', ['resolved', 'cancelled'])->get() as $f) {
            $ids = app(RenewalAccess::class)->projectIds($f->cycle->subscription)->all();
            if (array_intersect($scope, $ids) === []) {
                continue;
            }
            $expiry = app(Expiry::class)->instant($f->cycle->expiry_snapshot);
            $name = mb_substr(app(TelegramMessages::class)->clean($f->cycle->subscription->service_name ?? 'service #'.$f->cycle->service_subscription_id), 0, 80);
            if ($f->overdue($now)) {
                $items[] = ['rank' => 1, 'text' => 'OVERDUE follow-up #'.$f->id.' · '.$name];
            } elseif ($expiry && $expiry->lessThanOrEqualTo($now->addDays(7))) {
                $items[] = ['rank' => 2, 'text' => 'RENEWAL <=7 hari #'.$f->id.' · '.$name];
            } elseif (! $expiry) {
                $items[] = ['rank' => 5, 'text' => 'VERIFIKASI expiry unknown #'.$f->id.' · '.$name];
            }
        }

        foreach (OutboxEvent::forOrganization($org)->whereIn('id', $heldIds)->get() as $quietEvent) {
            $state = $quietEvent->aggregate_type === 'incident' ? Incident::forOrganization($org)->find($quietEvent->aggregate_id)?->state : 'review dashboard';
            $items[] = ['rank' => 6, 'text' => 'QUIET event '.$quietEvent->event_id.' · '.app(TelegramMessages::class)->clean($quietEvent->event_type).' · CURRENT '.$state];
        }
        $items = collect($items)->sortBy('rank')->values();
        $shownDown = $items->take(10)->pluck('incident_id')->filter()->values()->all();
        $text = ($bot->identity_fake ? "[FAKE TESTING]\n" : '').'DIGEST '.$event->payload['local_date']."\nEvent: ".$event->event_id."\nScope: ".app(TelegramMessages::class)->clean($dest->label)."\nWaktu: ".$now->setTimezone('Asia/Jakarta')->format('d-m-Y H:i')." WIB\nTotal prioritas: ".$items->count().' · quiet events: '.count($heldIds)."\n".$items->take(10)->pluck('text')->implode("\n")."\nBackup/maintenance: unsupported pada M2; coverage tidak diklaim sehat.\nAction owner/PIC: tinjau assignment per item di dashboard.\nTindakan: dahulukan critical, overdue, renewal dan verification gaps.\nMonitoring: ".rtrim(config('app.url'), '/').'/organizations/'.$org->id.'/overview'."\nRenewal: ".app(TelegramMessages::class)->link($org);

        return ['parts' => app(TelegramMessages::class)->segment($text), 'down_ids' => $shownDown, 'scope' => $scope];
    }
}
