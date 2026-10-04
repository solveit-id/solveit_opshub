<?php

namespace App\Jobs;

use App\Application\TelegramNotifications\DeliverySender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTelegramDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(DeliverySender $sender): void
    {
        $sender->send($this->deliveryId);
    }
}
