<?php

namespace App\Application\TelegramNotifications;

use App\Application\ClientTemplates\DraftGenerator;
use App\Application\RenewalFollowups\Expiry;
use App\Application\RenewalFollowups\RenewalAccess;
use App\Models\BackupInternalIncident;
use App\Models\ClientFollowup;
use App\Models\Incident;
use App\Models\IncidentNotificationHistory;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\RenewalReminder;
use App\Models\TelegramBinding;
use App\Models\TelegramBot;
use App\Models\TelegramDelivery;
use App\Models\TelegramDestination;
use App\Models\User;
use Carbon\CarbonImmutable;

class DeliveryReconciler
{
    public function before(TelegramDelivery $d, TelegramBot $bot, Organization $org, CarbonImmutable $now): bool
    {
        $event = OutboxEvent::forOrganization($org)->findOrFail($d->outbox_event_id);
        if ($d->identity_version !== $bot->identity_version || $event->status === 'cancelled') {
            return $this->stop($d, 'cancelled', 'SOURCE_OR_BOT_CHANGED');
        }
        if ($d->notification_kind === 'coalesced') {
            return $this->batch($d, $org, $now);
        }
        if ($event->aggregate_type === 'backup_internal_incident') {
            $issue = BackupInternalIncident::forOrganization($org)->find($event->aggregate_id);
            if (! $issue || $issue->state !== 'open') {
                return $this->stop($d, 'superseded', 'BACKUP_ISSUE_RESOLVED');
            }
        }
        if ($d->notification_kind === 'digest') {
            $snapshot = app(TelegramDigest::class)->current($org, $bot, TelegramDestination::findOrFail($d->telegram_destination_id), $event, $d->event_ids ?? [], $now);
            $siblings = TelegramDelivery::where('outbox_event_id', $event->id)->where('recipient_reference', $d->recipient_reference)->where('revision', $d->revision)->get();
            if (count($snapshot['parts']) !== $siblings->count()) {
                $this->replaceParts($d, $snapshot['parts'], $snapshot['scope'], $now, $snapshot['down_ids']);

                return false;
            }
            $d->update(['text' => $snapshot['parts'][$d->chunk_index], 'project_ids' => $snapshot['scope'], 'down_incident_ids' => $d->chunk_index === count($snapshot['parts']) - 1 ? $snapshot['down_ids'] : []]);
        }
        if ($event->aggregate_type === 'incident') {
            $incident = Incident::forOrganization($org)->findOrFail($event->aggregate_id);
            $recovered = in_array($incident->state, ['resolved', 'closed'], true);
            if ($d->notification_kind === 'recovered_summary') {
                return $recovered ? true : $this->stop($d, 'superseded', 'SUMMARY_STATE_CHANGED');
            }
            if (in_array($event->event_type, ['incident.opened', 'incident.stability_warning'], true) && $recovered) {
                if (! $this->hasDown($incident->id, $d->recipient_reference)) {
                    $text = ($bot->identity_fake ? "[FAKE TESTING]\n" : '')."INCIDENT TELAH PULIH — alert lama belum confirmed sent.\nEvent: ".$event->event_id."\nResource: incident #".$incident->id."\nEvidence: recovery terkonfirmasi ".$incident->confirmed_recovered_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i')." WIB\nAction owner/PIC: tinjau assignment dan closure di dashboard.\nTindakan: tinjau ringkasan recovery; ini bukan down alert baru.\nDashboard: ".app(TelegramMessages::class)->link($org, $event);
                    $text .= "\nProject scope: ".implode(', ', $d->project_ids)."\nSeverity: recovered summary (info)";
                    $summary = TelegramDelivery::firstOrCreate(['outbox_event_id' => $event->id, 'recipient_reference' => $d->recipient_reference, 'notification_kind' => 'recovered_summary', 'chunk_index' => 0, 'revision' => 1], ['organization_id' => $org->id, 'telegram_bot_id' => $bot->id, 'telegram_destination_id' => $d->telegram_destination_id, 'project_ids' => $d->project_ids, 'text' => $text, 'severity' => 'info', 'priority' => 2, 'available_at' => $now, 'identity_version' => $bot->identity_version, 'fake' => $bot->identity_fake]);
                    TelegramDelivery::where('outbox_event_id', $event->id)->where('recipient_reference', $d->recipient_reference)->whereIn('state', ['pending', 'retrying'])->where('id', '!=', $summary->id)->update(['state' => 'superseded', 'result_code' => 'INCIDENT_ALREADY_RECOVERED']);
                } else {
                    $this->stop($d, 'superseded', 'INCIDENT_ALREADY_RECOVERED');
                }

                return false;
            }
            if ($event->event_type === 'incident.resolved') {
                if (! $recovered || ! $this->hasDown($incident->id, $d->recipient_reference)) {
                    return $this->stop($d, 'superseded', 'NO_DELIVERED_DOWN_FOR_RECIPIENT');
                }
                $d->recovery_incident_ids = [$incident->id];
            } elseif ($d->notification_kind === 'internal') {
                $last = TelegramDelivery::where('outbox_event_id', $event->id)->where('recipient_reference', $d->recipient_reference)->where('revision', $d->revision)->where('notification_kind', 'internal')->max('chunk_index');
                $d->down_incident_ids = $d->chunk_index === $last ? [$incident->id] : [];
            }
            $d->save();
        }
        if (isset($event->payload['followup_id'])) {
            $f = ClientFollowup::forOrganization($org)->findOrFail($event->payload['followup_id']);
            if (in_array($event->event_type, ['renewal.reminder', 'renewal.verification_required'], true)) {
                if ($f->cycle->state !== 'active' || in_array($f->state, ['resolved', 'cancelled'], true)) {
                    return $this->stop($d, 'cancelled', 'FOLLOWUP_CLOSED');
                }
                if (($event->payload['kind'] ?? '') === 'escalation' && $f->acknowledged_at) {
                    return $this->stop($d, 'superseded', 'ACKNOWLEDGEMENT_RECORDED');
                }
                if (($event->payload['kind'] ?? '') === 'overdue' && ! $f->overdue($now)) {
                    return $this->stop($d, 'superseded', 'FOLLOWUP_DEADLINE_CHANGED');
                }
                if ($f->snoozed_until?->greaterThan($now)) {
                    $d->update(['available_at' => $f->snoozed_until, 'result_code' => 'FOLLOWUP_SNOOZED']);

                    return false;
                }
                $expiry = app(Expiry::class)->instant($f->cycle->expiry_snapshot);
                if (($event->payload['kind'] ?? '') === 'threshold' && $expiry) {
                    $zone = $f->cycle->expiry_snapshot['source_timezone'];
                    $days = (int) $now->setTimezone($zone)->startOfDay()->diffInDays($expiry->setTimezone($zone)->startOfDay(), false);
                    if ($days > $event->payload['threshold']) {
                        return $this->stop($d, 'superseded', 'THRESHOLD_NO_LONGER_APPLICABLE');
                    }
                    if (RenewalReminder::where('renewal_cycle_id', $f->renewal_cycle_id)->where('kind', 'threshold')->where('threshold', '<', $event->payload['threshold'])->where('state', 'pending')->exists()) {
                        return $this->stop($d, 'superseded', 'LATEST_THRESHOLD_EXISTS');
                    }
                }
            }
            if ($d->telegram_destination_id && in_array($d->notification_kind, ['internal', 'client_template'], true)) {
                $current = clone $event;
                $current->payload = [...$event->payload, 'impacted_project_ids' => app(RenewalAccess::class)->projectIds($f->cycle->subscription)->all(), 'severity' => $event->event_type === 'renewal.verified' ? 'info' : $f->severity];
                $messages = app(TelegramMessages::class)->render($current, TelegramDestination::findOrFail($d->telegram_destination_id));
                $existing = TelegramDelivery::where('outbox_event_id', $event->id)->where('recipient_reference', $d->recipient_reference)->where('revision', $d->revision)->whereIn('notification_kind', ['internal', 'client_template'])->orderBy('chunk_index')->get();
                $changed = $existing->count() !== count($messages) || $existing->contains(fn ($item) => ! isset($messages[$item->chunk_index]) || $item->text !== $messages[$item->chunk_index]['text'] || $item->project_ids !== $messages[$item->chunk_index]['project_ids'] || $item->severity !== $current->payload['severity']);
                if ($changed) {
                    $revision = TelegramDelivery::where('outbox_event_id', $event->id)->where('recipient_reference', $d->recipient_reference)->max('revision') + 1;
                    $parent = null;
                    foreach ($messages as $index => $message) {
                        $next = TelegramDelivery::create(['organization_id' => $org->id, 'telegram_bot_id' => $bot->id, 'telegram_destination_id' => $d->telegram_destination_id, 'outbox_event_id' => $event->id, 'recipient_reference' => $d->recipient_reference, 'notification_kind' => $message['kind'], 'chunk_index' => $index, 'revision' => $revision, 'parent_delivery_id' => $parent, 'project_ids' => $message['project_ids'], 'text' => $message['text'], 'reply_markup' => $message['reply_markup'], 'severity' => $current->payload['severity'], 'priority' => array_search($current->payload['severity'], ['critical', 'warning', 'info']), 'available_at' => $now, 'identity_version' => $bot->identity_version, 'fake' => $bot->identity_fake]);
                        $parent = $next->id;
                    }
                    TelegramDelivery::where('outbox_event_id', $event->id)->where('recipient_reference', $d->recipient_reference)->where('revision', '<', $revision)->whereIn('state', ['pending', 'retrying'])->update(['state' => 'superseded', 'result_code' => 'CURRENT_SOURCE_REFRESHED']);

                    return false;
                }
                if ($d->notification_kind === 'internal') {
                    $d->update(['reply_markup' => $messages[$d->chunk_index]['reply_markup']]);
                }
            }
            if ($d->notification_kind === 'private_reply' && isset($event->payload['template_key'])) {
                $binding = TelegramBinding::findOrFail($d->binding_id);
                $actor = User::findOrFail($binding->user_id);
                app(RenewalAccess::class)->require($actor, $org, $f->cycle->subscription, 'followup.manage');
                $draft = app(DraftGenerator::class)->generate($org, $f, $f->cycle->state === 'verified' ? 'TPL-10' : $event->payload['template_key'], $actor);
                $body = $draft->draft_status === 'ready' && $f->state !== 'cancelled' ? $draft->rendered_body : 'Draft blocked/closed. Review current data di dashboard: '.app(TelegramMessages::class)->link($org, $event);
                $parts = app(TelegramMessages::class)->segment($body);
                $projects = app(RenewalAccess::class)->projectIds($f->cycle->subscription)->all();
                if (count($parts) !== TelegramDelivery::where('outbox_event_id', $event->id)->where('revision', $d->revision)->count()) {
                    $this->replaceParts($d, $parts, $projects, $now);

                    return false;
                }
                $d->update(['text' => $parts[$d->chunk_index], 'project_ids' => $projects]);
            }
        }

        return true;
    }

    public function hasDown(int $incidentId, string $recipient): bool
    {
        $incident = Incident::findOrFail($incidentId);

        return IncidentNotificationHistory::where('incident_id', $incidentId)->where('recipient_reference', $recipient)->whereIn('down_delivery_id', TelegramDelivery::where('state', 'sent')->whereNotNull('message_id')->where('sent_at', '>=', $incident->confirmed_down_at)->whereJsonContains('down_incident_ids', $incidentId)->when(! app()->environment('testing'), fn ($q) => $q->where('fake', false))->select('id'))->exists();
    }

    private function stop(TelegramDelivery $d, string $state, string $code): bool
    {
        $d->update(['state' => $state, 'result_code' => $code]);

        return false;
    }

    private function replaceParts(TelegramDelivery $d, array $parts, array $projects, CarbonImmutable $now, array $down = []): void
    {
        $revision = TelegramDelivery::where('outbox_event_id', $d->outbox_event_id)->where('recipient_reference', $d->recipient_reference)->max('revision') + 1;
        $parent = null;
        foreach ($parts as $index => $part) {
            $next = $d->replicate(['state', 'result_code', 'attempts', 'first_attempt_at', 'last_attempt_at', 'lease_owner', 'lease_until', 'lease_version', 'queued_at', 'message_id', 'sent_at', 'http_status', 'uncertain']);
            $next->fill(['text' => $part, 'project_ids' => $projects, 'chunk_index' => $index, 'revision' => $revision, 'parent_delivery_id' => $parent, 'available_at' => $now, 'down_incident_ids' => $index === count($parts) - 1 ? $down : [], 'recovery_incident_ids' => []]);
            $next->save();
            $parent = $next->id;
        }
        TelegramDelivery::where('outbox_event_id', $d->outbox_event_id)->where('recipient_reference', $d->recipient_reference)->where('revision', '<', $revision)->whereIn('state', ['pending', 'retrying'])->update(['state' => 'superseded', 'result_code' => 'CURRENT_SOURCE_REFRESHED']);
    }

    private function batch(TelegramDelivery $d, Organization $org, CarbonImmutable $now): bool
    {
        $events = OutboxEvent::forOrganization($org)->whereIn('id', $d->event_ids)->get();
        $down = [];
        $recovery = [];
        $lines = [];
        $severity = 'info';
        foreach ($events as $event) {
            if ($event->aggregate_type === 'incident') {
                $i = Incident::forOrganization($org)->find($event->aggregate_id);
                if (! $i) {
                    continue;
                }
                $lines[] = 'Event '.$event->event_id.' · incident #'.$i->id.' · CURRENT '.$i->state;
                if (in_array($event->event_type, ['incident.opened', 'incident.stability_warning'], true) && ! in_array($i->state, ['resolved', 'closed'], true)) {
                    $down[] = $i->id;
                    if ($i->severity === 'critical' || $severity === 'info') {
                        $severity = $i->severity;
                    }
                }
                if ($event->event_type === 'incident.resolved' && $this->hasDown($i->id, $d->recipient_reference)) {
                    $recovery[] = $i->id;
                }
            } else {
                if ($event->status !== 'cancelled' && (($event->payload['severity'] ?? 'info') === 'critical' || $severity === 'info')) {
                    $severity = $event->payload['severity'] ?? 'info';
                }
                $lines[] = 'Event '.$event->event_id.' · '.app(TelegramMessages::class)->clean($event->event_type).' · '.($event->status === 'cancelled' ? 'cancelled' : 'review dashboard');
            }
        }
        $text = ($d->fake ? "[FAKE TESTING]\n" : '').'RINGKASAN '.strtoupper($d->severity).' · '.$events->count()." event\nIncident aktif: ".count($down).' · recovery dengan histori: '.count($recovery)."\n".implode("\n", array_slice($lines, 0, 10)).(count($lines) > 10 ? "\n+".(count($lines) - 10).' event lainnya; semua retained di dashboard.' : '')."\nWaktu: ".$now->setTimezone('Asia/Jakarta')->format('d-m-Y H:i')." WIB\nAction owner/PIC: assignment per event di dashboard.\nTindakan: tinjau current state/evidence, bukan urutan down lama.\nDashboard monitoring: ".rtrim(config('app.url'), '/').'/organizations/'.$org->id.'/overview'."\nDashboard renewal: ".app(TelegramMessages::class)->link($org);
        $text = str_replace('RINGKASAN '.strtoupper($d->severity), 'RINGKASAN '.strtoupper($severity), $text);
        $d->update(['text' => $text, 'severity' => $severity, 'priority' => array_search($severity, ['critical', 'warning', 'info']), 'down_incident_ids' => array_values(array_unique($down)), 'recovery_incident_ids' => array_values(array_unique($recovery))]);

        return true;
    }
}
