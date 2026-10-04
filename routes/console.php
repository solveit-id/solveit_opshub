<?php

use App\Jobs\DispatchPendingOutboxEvents;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new DispatchPendingOutboxEvents)->everyMinute();
Schedule::command('opshub:monitoring:schedule')->everyMinute()->withoutOverlapping();
