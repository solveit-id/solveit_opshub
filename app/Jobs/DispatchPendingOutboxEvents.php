<?php

namespace App\Jobs;

use App\Application\TelegramNotifications\DeliveryMaterializer;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\TelegramDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DispatchPendingOutboxEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue(config('opshub.queue.notification'));
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $events = OutboxEvent::query()
                ->where('status', 'pending')
                // Non-notification bookkeeping only; provider intent must have durable deliveries.
                ->whereIn('event_type', ['foundation.changed'])
                ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now('UTC')))
                ->orderBy('id')
                ->lockForUpdate()
                ->limit(100)
                ->get();

            foreach ($events as $event) {
                $event->update([
                    'status' => 'processed',
                    'dispatched_at' => now('UTC'),
                    'attempts' => $event->attempts + 1,
                ]);
            }
        });
        Organization::where('is_active', true)->each(fn ($org) => app(DeliveryMaterializer::class)->materialize($org));
        // Until reconciliation owns operational dispatch, only the explicit Owner test intent is queued.
        TelegramDelivery::where('state', 'pending')->whereIn('outbox_event_id', OutboxEvent::where('event_type', 'telegram.test_requested')->select('id'))->each(fn ($d) => SendTelegramDelivery::dispatch($d->id)->onQueue(config('opshub.queue.notification')));
    }
}
