<?php

namespace App\Jobs;

use App\Application\Backups\RestoreDrills;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunRestoreDrill implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $drillId) {}

    public function handle(RestoreDrills $drills): void
    {
        $drills->execute($this->drillId);
    }
}
