<?php

namespace App\Console\Commands;

use App\Infrastructure\Testing\MonitoringDemo;
use Illuminate\Console\Command;
use LogicException;

class DemoMonitoring extends Command
{
    protected $signature = 'opshub:monitoring:demo {--browser : Leave an open fixture incident and save an ignored disposable login manifest} {--recover= : Add two fake successes to an existing isolated demo organization}';

    protected $description = 'Run the M1 deterministic fake loop only in an isolated MySQL testing database';

    public function handle(MonitoringDemo $demo): int
    {
        try {
            $report = $this->option('recover') !== null ? $demo->recover((int) $this->option('recover')) : $demo->run((bool) $this->option('browser'));
            $this->line(json_encode($report, JSON_PRETTY_PRINT));
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
