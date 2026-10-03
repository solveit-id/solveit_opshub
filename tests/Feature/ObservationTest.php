<?php

namespace Tests\Feature;

use App\Application\Monitoring\MonitoringHealth;
use App\Application\Monitoring\ObservationRecorder;
use App\Application\Monitoring\ObservationRetention;
use App\Infrastructure\Monitoring\ProbeResult;
use App\Models\Asset;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\Policy;
use App\Models\PolicyVersion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ObservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_leases_freshness_immutable_evidence_and_retention_preview(): void
    {
        $monitor = $this->monitor();
        $now = CarbonImmutable::parse('2026-10-04T00:00:00Z');
        $recorder = app(ObservationRecorder::class);
        $this->assertSame('never_observed', app(MonitoringHealth::class)->monitor($monitor, $now)['freshness']);
        $token = $recorder->begin($monitor, $now);
        $this->assertNull($recorder->begin($monitor, $now));
        $observation = $recorder->finish($monitor, $token, $now, $now, $now->addSecond(), new ProbeResult('pass', 'HTTP_OK', ['status_code' => 200, 'body' => 'secret'], fake: true));
        $this->assertSame(['status_code' => 200], $observation->evidence);
        $this->assertSame('fake_probe', $observation->source_type);
        $this->assertSame('fresh', app(MonitoringHealth::class)->monitor($monitor, $now)['freshness']);
        $this->assertSame('stale', app(MonitoringHealth::class)->monitor($monitor, $now->addSeconds(182))['freshness']);
        $this->assertSame('unknown', app(MonitoringHealth::class)->monitor($monitor, $now->addSeconds(182))['state']);
        $this->assertSame(1, app(ObservationRetention::class)->aggregate($now->addDay()));
        $this->assertSame(1, app(ObservationRetention::class)->preview($now->addDays(31))['raw_http_candidates']);
        $this->assertDatabaseCount('observations', 1);
        $this->assertDatabaseHas('daily_monitor_aggregates', ['passed' => 1, 'failed' => 0]);
        $this->expectException(LogicException::class);
        $observation->update(['outcome' => 'fail']);
    }

    public function test_expired_lease_cannot_persist_and_retry_is_deduplicated(): void
    {
        $monitor = $this->monitor();
        $now = CarbonImmutable::now('UTC');
        $recorder = app(ObservationRecorder::class);
        $token = $recorder->begin($monitor, $now);
        $replacement = $recorder->begin($monitor, $now->addSeconds(46));
        try {
            $recorder->finish($monitor, $token, $now, $now, $now->addSeconds(47), new ProbeResult('fail', 'NETWORK_TIMEOUT'));
            $this->fail('Old lease must be fenced');
        } catch (LogicException) {
            $this->assertDatabaseCount('observations', 0);
        }
        $recorder->finish($monitor, $replacement, $now, $now, $now->addSeconds(47), new ProbeResult('pass', 'HTTP_OK'));
        $retry = $recorder->begin($monitor, $now->addSeconds(48));
        $recorder->finish($monitor, $retry, $now, $now, $now->addSeconds(49), new ProbeResult('fail', 'NETWORK_TIMEOUT'));
        $this->assertDatabaseCount('observations', 1);
        $this->assertDatabaseHas('observations', ['outcome' => 'pass']);
    }

    private function monitor(): Monitor
    {
        $org = Organization::create(['name' => 'Fixture']);
        $asset = Asset::create(['organization_id' => $org->id, 'kind' => 'url', 'canonical_identity' => 'https://public.example']);
        $policy = Policy::create(['organization_id' => $org->id, 'name' => 'Fixture', 'kind' => 'monitoring']);
        $version = PolicyVersion::create(['organization_id' => $org->id, 'policy_id' => $policy->id, 'version' => 1, 'configuration' => []]);

        return Monitor::create(['organization_id' => $org->id, 'asset_id' => $asset->id, 'policy_version_id' => $version->id, 'kind' => 'http', 'environment_kind' => 'production', 'configuration_digest' => hash('sha256', 'fixture'), 'configuration' => [], 'interval_seconds' => 60]);
    }
}
