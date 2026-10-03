<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
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
                ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->orderBy('id')
                ->lockForUpdate()
                ->limit(100)
                ->get();

            foreach ($events as $event) {
                $event->update([
                    'status' => 'processed',
                    'dispatched_at' => now(),
                    'attempts' => $event->attempts + 1,
                ]);
            }
        });
    }
}
