<?php

namespace App\Console\Commands;

use App\Application\RenewalFollowups\RenewalScheduler;
use App\Models\Organization;
use Illuminate\Console\Command;

class ScheduleRenewals extends Command
{
    protected $signature = 'opshub:renewals:schedule';

    protected $description = 'Evaluate canonical renewal reminders without external delivery';

    public function handle(RenewalScheduler $scheduler): int
    {
        $count = 0;
        foreach (Organization::where('is_active', true)->get() as $org) {
            $count += count($scheduler->tick($org));
        }
        $this->info("{$count} canonical renewal events recorded; this is not Telegram delivery.");

        return self::SUCCESS;
    }
}
