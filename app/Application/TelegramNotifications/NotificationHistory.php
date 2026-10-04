<?php

namespace App\Application\TelegramNotifications;

use App\Models\IncidentNotificationHistory;
use App\Models\TelegramDelivery;

class NotificationHistory
{
    public function sent(TelegramDelivery $delivery): void
    {
        if ($delivery->state !== 'sent' || ! $delivery->message_id) {
            return;
        }
        foreach ($delivery->down_incident_ids ?? [] as $id) {
            $history = IncidentNotificationHistory::firstOrCreate(['incident_id' => $id, 'recipient_reference' => $delivery->recipient_reference], ['organization_id' => $delivery->organization_id, 'down_delivery_id' => $delivery->id, 'fake' => $delivery->fake]);
            $previous = $history->down_delivery_id ? TelegramDelivery::find($history->down_delivery_id) : null;
            if (! $previous || $previous->sent_at->lessThan($delivery->sent_at)) {
                $history->update(['down_delivery_id' => $delivery->id, 'recovery_delivery_id' => null, 'fake' => $delivery->fake]);
            }
        }
        foreach ($delivery->recovery_incident_ids ?? [] as $id) {
            IncidentNotificationHistory::where('incident_id', $id)->where('recipient_reference', $delivery->recipient_reference)->whereNotNull('down_delivery_id')->update(['recovery_delivery_id' => $delivery->id]);
        }
    }
}
