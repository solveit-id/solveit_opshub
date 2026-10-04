<?php

use App\Application\Monitoring\IncidentEngine;
use App\Application\Monitoring\ObservationRecorder;
use App\Application\PolicyScheduling\JobSlotService;
use App\Models\Monitor;
use App\Models\Observation;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'mysql' || ! str_ends_with(config('database.connections.mysql.database'), '_test') || config('database.connections.mysql.url')) {
    fwrite(STDERR, "Refusing non-isolated test environment.\n");
    exit(1);
}
[$script, $operation, $id, $readyAt] = $argv;
while (microtime(true) < (float) $readyAt) {
    usleep(1000);
}
$monitor = Monitor::findOrFail((int) $id);
if ($operation === 'reserve') {
    $run = app(JobSlotService::class)->reserve(Organization::findOrFail($monitor->organization_id), 'monitor.http', CarbonImmutable::parse('2026-10-04T00:00:00Z'), 'monitor', $monitor->id, $monitor->policy_version_id);
    echo $run->id;
} elseif ($operation === 'lease') {
    echo app(ObservationRecorder::class)->begin($monitor, CarbonImmutable::now('UTC')) === null ? 'busy' : 'acquired';
} elseif ($operation === 'incident') {
    echo app(IncidentEngine::class)->evaluate(Observation::where('monitor_id', $monitor->id)->latest('id')->firstOrFail())->id;
} else {
    fwrite(STDERR, "Unsupported race operation.\n");
    exit(1);
}
