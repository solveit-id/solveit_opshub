<?php

namespace App\Application\ClientTemplates;

use App\Models\ClientFollowup;
use App\Models\RenewalCycle;
use App\Models\TemplateDraft;

class DraftInvalidator
{
    public function services(array $ids): void
    {
        $cycles = RenewalCycle::whereIn('service_subscription_id', $ids)->select('id');
        $followups = ClientFollowup::whereIn('renewal_cycle_id', $cycles)->select('id');
        TemplateDraft::whereIn('client_followup_id', $followups)->whereIn('draft_status', ['ready', 'blocked_missing_data'])->update(['draft_status' => 'stale']);
    }

    public function contact(int $id): void
    {
        TemplateDraft::where('contact_id', $id)->whereIn('draft_status', ['ready', 'blocked_missing_data'])->update(['draft_status' => 'stale']);
    }
}
