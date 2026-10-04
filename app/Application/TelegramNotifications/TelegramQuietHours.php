<?php

namespace App\Application\TelegramNotifications;

use App\Models\IncidentNotificationHistory;
use App\Models\OutboxEvent;
use App\Models\TelegramBot;
use App\Models\TelegramDelivery;
use Carbon\CarbonImmutable;

class TelegramQuietHours
{
    public function defer(TelegramDelivery $d, TelegramBot $bot, CarbonImmutable $now): bool
    {
        $criticalRecovery = collect($d->recovery_incident_ids ?? [])->contains(fn ($id) => IncidentNotificationHistory::where('incident_id', $id)->where('recipient_reference', $d->recipient_reference)->whereIn('down_delivery_id', TelegramDelivery::where('state', 'sent')->where('severity', 'critical')->select('id'))->exists());
        if ($d->severity === 'critical' || $criticalRecovery || in_array($d->notification_kind, ['private_reply', 'binding_notice', 'digest'], true) || OutboxEvent::findOrFail($d->outbox_event_id)->event_type === 'telegram.test_requested' || ! $this->quiet($bot, $now)) {
            return false;
        }
        $d->update(['available_at' => $this->digestAt($bot, $now), 'result_code' => 'QUIET_HOURS', 'queued_at' => null]);

        return true;
    }

    public function quiet(TelegramBot $bot, CarbonImmutable $now): bool
    {
        $settings = $bot->settings;
        $time = $now->setTimezone($settings['timezone'])->format('H:i');
        $start = $settings['quiet_start'];
        $end = $settings['quiet_end'];

        return $start === $end ? false : ($start < $end ? $time >= $start && $time < $end : $time >= $start || $time < $end);
    }

    public function digestAt(TelegramBot $bot, CarbonImmutable $now): CarbonImmutable
    {
        $local = $now->setTimezone($bot->settings['timezone']);
        [$h, $m] = array_map('intval', explode(':', $bot->settings['digest_time']));
        $at = $local->setTime($h, $m);
        if ($at->lessThanOrEqualTo($local)) {
            $at = $at->addDay();
        }

        return $at->utc();
    }
}
