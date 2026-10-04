<?php

namespace App\Application\TelegramNotifications;

use App\Models\Organization;
use App\Models\TelegramBot;
use App\Models\TelegramDelivery;
use App\Models\TelegramIntegrationFinding;

class NotificationHealth
{
    public function snapshot(Organization $org): array
    {
        $bot = TelegramBot::forOrganization($org)->first();
        $last = TelegramDelivery::forOrganization($org)->where('state', 'sent')->latest('sent_at')->first();
        $state = ! $bot ? 'not_configured' : (! $bot->enabled ? 'disabled' : ($bot->identity_fake ? 'fake_tested' : (! config('opshub.live_connectors_enabled') ? 'live_disabled' : 'configured_live_unverified')));
        if (TelegramIntegrationFinding::forOrganization($org)->where('state', '!=', 'resolved')->exists()) {
            $state = 'degraded';
        }

        return ['state' => $state, 'last_observed_at' => $last?->sent_at?->toIso8601String(), 'boundary' => 'Sent means provider acceptance/message ID, not read receipt; fake is not live evidence.'];
    }
}
