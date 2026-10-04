<?php

namespace App\Console\Commands;

use App\Application\Monitoring\MonitoringScheduler;
use App\Models\Organization;
use Illuminate\Console\Command;

class ScheduleMonitoring extends Command
{
    protected $signature = 'opshub:monitoring:schedule';

    protected $description = 'Synchronize active monitoring and enqueue one latest eligible slot per canonical monitor';

    public function handle(MonitoringScheduler $scheduler): int
    {
        $scheduled = 0;
        foreach (Organization::where('is_active', true)->get() as $org) {
            $scheduled += $scheduler->tick($org);
        }
        $this->info("Queued {$scheduled} canonical monitoring slots.");

        return self::SUCCESS;
    }
}
