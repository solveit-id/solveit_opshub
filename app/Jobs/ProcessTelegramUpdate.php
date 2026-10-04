<?php

namespace App\Jobs;

use App\Application\TelegramNotifications\TelegramUpdateProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $receiptId) {}

    public function handle(TelegramUpdateProcessor $processor): void
    {
        $processor->process($this->receiptId);
    }
}
