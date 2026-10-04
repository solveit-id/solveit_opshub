<?php

namespace App\Console\Commands;

use App\Application\TelegramNotifications\NotificationDispatcher;
use App\Models\Organization;
use Illuminate\Console\Command;

class DispatchTelegram extends Command
{
    protected $signature = 'opshub:telegram:dispatch';

    protected $description = 'Reconcile durable notifications, digest and queue bounded Telegram delivery';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $count = 0;
        foreach (Organization::where('is_active', true)->get() as $org) {
            $count += $dispatcher->tick($org);
        }
        $this->info($count.' deliveries queued; provider acceptance/readiness must be checked in history.');

        return self::SUCCESS;
    }
}
