<?php

namespace Tests\Support;

use App\Application\Monitoring\IncidentEngine;
use App\Application\Monitoring\ObservationRecorder;
use App\Application\PolicyScheduling\MonitoringPolicyConfiguration;
use App\Infrastructure\Monitoring\ProbeResult;
use App\Models\Asset;
use App\Models\AssetUsage;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Monitor;
use App\Models\Observation;
use App\Models\Organization;
use App\Models\Policy;
use App\Models\PolicyVersion;
use App\Models\Project;
use Carbon\CarbonImmutable;

trait MonitoringFixture
{
    protected function graph(): array
    {
        $org = Organization::create(['name' => 'Fictitious Solveit', 'timezone' => 'Asia/Jakarta']);
        $client = Client::create(['organization_id' => $org->id, 'name' => 'PT Contoh']);
        $project = Project::create(['organization_id' => $org->id, 'client_id' => $client->id, 'code' => 'DEMO', 'name' => 'Demo', 'lifecycle' => 'active']);
        $env = Environment::create(['organization_id' => $org->id, 'project_id' => $project->id, 'kind' => 'production', 'display_name' => 'Production']);
        $asset = Asset::create(['organization_id' => $org->id, 'kind' => 'url', 'canonical_identity' => 'https://public.example']);
        AssetUsage::create(['organization_id' => $org->id, 'asset_id' => $asset->id, 'project_id' => $project->id, 'environment_id' => $env->id, 'purpose' => 'public_endpoint']);
        $policy = Policy::create(['organization_id' => $org->id, 'name' => 'Fixture', 'kind' => 'monitoring']);
        $version = PolicyVersion::create(['organization_id' => $org->id, 'policy_id' => $policy->id, 'version' => 1, 'configuration' => app(MonitoringPolicyConfiguration::class)->defaults()]);
        $monitor = Monitor::create(['organization_id' => $org->id, 'asset_id' => $asset->id, 'policy_version_id' => $version->id, 'kind' => 'http', 'environment_kind' => 'production', 'configuration_digest' => hash('sha256', 'fixture'), 'configuration' => ['failure_threshold' => 3, 'recovery_threshold' => 2], 'interval_seconds' => 60]);
        $monitor->projects()->attach($project->id, ['environment_id' => $env->id]);

        return [$org, $project, $monitor, $env, $version];
    }

    protected function sample(Monitor $monitor, CarbonImmutable $at, string $outcome): Observation
    {
        $recorder = app(ObservationRecorder::class);
        $token = $recorder->begin($monitor, $at);
        $observation = $recorder->finish($monitor, $token, $at, $at, $at->addSecond(), new ProbeResult($outcome, $outcome === 'pass' ? 'HTTP_OK' : 'HTTP_STATUS', ['status_code' => $outcome === 'pass' ? 200 : 503], fake: true), app(IncidentEngine::class)->inMaintenance($monitor, $at));
        app(IncidentEngine::class)->evaluate($observation);

        return $observation;
    }
}
