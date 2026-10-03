<?php

namespace App\Application\Monitoring;

use App\Models\Observation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ObservationRetention
{
    /** Build daily aggregates before an Owner-reviewed cleanup; incident evidence is held. */
    public function aggregate(CarbonImmutable $before): int
    {
        $groups = Observation::where('completed_at', '<', $before->startOfDay())->get()->groupBy(fn ($observation) => $observation->monitor_id.':'.$observation->completed_at->toDateString());
        foreach ($groups as $samples) {
            $first = $samples->first();
            DB::table('daily_monitor_aggregates')->updateOrInsert(['monitor_id' => $first->monitor_id, 'day' => $first->completed_at->toDateString()], [
                'passed' => $samples->where('outcome', 'pass')->count(), 'failed' => $samples->where('outcome', 'fail')->count(),
                'unknown' => $samples->whereNotIn('outcome', ['pass', 'fail'])->count(), 'maintenance' => $samples->where('maintenance', true)->count(), 'samples' => $samples->count(),
            ]);
        }

        return $groups->count();
    }

    public function preview(CarbonImmutable $now): array
    {
        return ['raw_http_candidates' => Observation::where('completed_at', '<', $now->subDays(30))->where('retention_hold', false)->whereIn('monitor_id', DB::table('monitors')->where('kind', 'http')->select('id'))->count(),
            'raw_retention_days' => 30, 'daily_retention_months' => 12, 'mode' => 'preview_only', 'reason' => 'Owner review and hold controls required before deletion.'];
    }
}
