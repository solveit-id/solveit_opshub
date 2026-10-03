<?php

namespace App\Application\Monitoring;

use App\Infrastructure\Monitoring\ProbeResult;
use App\Infrastructure\Security\SensitiveDataRedactor;
use App\Models\Monitor;
use App\Models\Observation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class ObservationRecorder
{
    public function begin(Monitor $monitor, CarbonImmutable $now): ?string
    {
        return DB::transaction(function () use ($monitor, $now): ?string {
            $locked = Monitor::whereKey($monitor->id)->lockForUpdate()->firstOrFail();
            if (! $locked->enabled || ($locked->leased_until !== null && $locked->leased_until->greaterThan($now))) {
                return null;
            }
            $token = (string) Str::uuid();
            $locked->update(['lease_token' => $token, 'leased_until' => $now->addSeconds(45)]);

            return $token;
        });
    }

    public function finish(Monitor $monitor, string $token, CarbonImmutable $scheduled, CarbonImmutable $started, CarbonImmutable $completed, ProbeResult $result, bool $maintenance = false): Observation
    {
        return DB::transaction(function () use ($monitor, $token, $scheduled, $started, $completed, $result, $maintenance): Observation {
            $locked = Monitor::whereKey($monitor->id)->lockForUpdate()->firstOrFail();
            if ($locked->lease_token !== $token || $locked->leased_until->lessThan($completed)) {
                throw new LogicException('Monitor lease is no longer owned by this run.');
            }
            if ($scheduled->greaterThan($started) || $started->greaterThan($completed) || ! in_array($result->outcome, ['pass', 'warn', 'fail', 'unknown', 'unsupported', 'not_applicable'], true)) {
                throw new LogicException('Invalid observation chronology or outcome.');
            }
            $evidence = array_intersect_key($result->evidence, array_flip(['status_code', 'latency_ms', 'redirect_statuses', 'content_check', 'expires_at', 'remaining_days', 'hostname_valid', 'chain_valid', 'record_type', 'values', 'expected_check']));
            $evidence = app(SensitiveDataRedactor::class)->redactArray($evidence);
            $freshness = $locked->interval_seconds <= 300 ? max(180, 3 * $locked->interval_seconds) : ($locked->kind === 'tls' ? 90000 : max(180, 3 * $locked->interval_seconds));
            $observation = Observation::firstOrCreate(['monitor_id' => $locked->id, 'scheduled_at' => $scheduled], [
                'organization_id' => $locked->organization_id, 'started_at' => $started, 'completed_at' => $completed, 'observed_at' => $completed, 'received_at' => $completed,
                'fresh_until' => $completed->addSeconds($freshness), 'outcome' => $result->outcome, 'reason_code' => $result->reason,
                'source_type' => $result->fake ? 'fake_probe' : 'public_probe', 'source_id' => 'monitor:'.$locked->id,
                'quality' => $result->fake ? 'fixture' : 'verified', 'unit' => $locked->kind === 'http' ? 'http_status' : null,
                'value' => ['outcome' => $result->outcome], 'evidence' => $evidence, 'fake' => $result->fake, 'maintenance' => $maintenance,
            ]);
            $locked->update(['lease_token' => null, 'leased_until' => null]);

            return $observation;
        });
    }
}
