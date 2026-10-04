<?php

namespace App\Infrastructure\Testing;

use App\Application\Monitoring\IncidentActions;
use App\Application\Monitoring\IncidentEngine;
use App\Application\Monitoring\MonitoringHealth;
use App\Application\Monitoring\MonitoringScheduler;
use App\Application\Monitoring\ObservationRecorder;
use App\Application\Monitoring\RuntimeHealth;
use App\Application\PolicyScheduling\MonitoringPolicyConfiguration;
use App\Domain\IdentityAccess\Role;
use App\Jobs\ProbeMonitor;
use App\Models\Asset;
use App\Models\AssetUsage;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Incident;
use App\Models\JobRun;
use App\Models\Membership;
use App\Models\Monitor;
use App\Models\Observation;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\PolicyVersion;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

class MonitoringDemo
{
    public function recover(int $organizationId): array
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql' || ! str_ends_with(config('database.connections.mysql.database'), '_test') || config('database.connections.mysql.url')) {
            throw new LogicException('Recovery demo requires an isolated MySQL testing database.');
        }
        $org = Organization::findOrFail($organizationId);
        if ($org->name !== 'M1 Fictitious Demo' || Observation::forOrganization($org)->where('fake', false)->exists()) {
            throw new LogicException('Recovery is restricted to fictitious fake-only demo records.');
        }
        $monitor = Monitor::forOrganization($org)->where('enabled', true)->sole();
        $latest = $monitor->observations()->latest('scheduled_at')->firstOrFail();
        $start = CarbonImmutable::now('UTC')->startOfMinute()->max($latest->scheduled_at->addMinute());
        $executor = new ScriptedProbeExecutor(['pass', 'pass']);
        try {
            foreach ([0, 1] as $offset) {
                $at = $start->addMinutes($offset);
                CarbonImmutable::setTestNow($at);
                app(MonitoringScheduler::class)->tick($org, $at);
                $run = JobRun::forOrganization($org)->where('state', 'queued')->where('scheduled_slot', $at->format('Y-m-d H:i:s'))->sole();
                (new ProbeMonitor($run->id))->handle(app(ObservationRecorder::class), app(IncidentEngine::class), $executor);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }

        return ['organization_id' => $org->id, 'monitor_state' => $monitor->fresh()->state, 'incident_state' => Incident::forOrganization($org)->latest('id')->firstOrFail()->state, 'fake' => true];
    }

    public function run(bool $browser = false): array
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql' || ! str_ends_with(config('database.connections.mysql.database'), '_test') || config('database.connections.mysql.url')) {
            throw new LogicException('Demo requires APP_ENV=testing, MySQL _test schema and no DB_URL.');
        }
        $org = Organization::create(['name' => 'M1 Fictitious Demo', 'timezone' => 'Asia/Jakarta']);
        $password = Str::random(32);
        $owner = User::create(['name' => 'M1 Demo Owner', 'email' => 'm1-owner-'.$org->id.'@example.test', 'email_verified_at' => now(), 'password' => Hash::make($password), 'is_active' => true]);
        Membership::create(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => Role::Owner, 'is_active' => true]);
        $client = Client::create(['organization_id' => $org->id, 'name' => 'PT Contoh Fiktif']);
        $asset = Asset::create(['organization_id' => $org->id, 'kind' => 'url', 'canonical_identity' => 'https://public.example', 'source' => 'fictitious_fixture']);
        $policy = Policy::create(['organization_id' => $org->id, 'name' => 'M1 Demo', 'kind' => 'monitoring']);
        $configuration = app(MonitoringPolicyConfiguration::class)->defaults();
        $configuration['checks']['tls']['enabled'] = false;
        $configuration['checks']['dns']['enabled'] = false;
        $version = PolicyVersion::create(['organization_id' => $org->id, 'policy_id' => $policy->id, 'version' => 1, 'configuration' => $configuration, 'published_at' => now('UTC'), 'published_by_user_id' => $owner->id]);
        $projects = [];
        foreach (['WEB', 'API'] as $code) {
            $project = Project::create(['organization_id' => $org->id, 'client_id' => $client->id, 'code' => $code, 'name' => 'Demo '.$code, 'lifecycle' => 'active', 'internal_pic_user_id' => $owner->id]);
            $env = Environment::create(['organization_id' => $org->id, 'project_id' => $project->id, 'kind' => 'production', 'display_name' => 'Production']);
            AssetUsage::create(['organization_id' => $org->id, 'asset_id' => $asset->id, 'project_id' => $project->id, 'environment_id' => $env->id, 'purpose' => 'public_endpoint']);
            PolicyAssignment::create(['organization_id' => $org->id, 'policy_version_id' => $version->id, 'resource_type' => 'project', 'resource_id' => $project->id, 'is_active' => true]);
            $projects[] = $project;
        }
        $now = CarbonImmutable::now('UTC')->startOfMinute();
        $start = $now->subMinutes($browser ? 8 : 5);
        $executor = new ScriptedProbeExecutor(['pass', 'fail', 'fail', 'fail', 'pass', 'pass', ...($browser ? ['fail', 'fail', 'fail'] : [])]);
        $states = [];
        try {
            foreach (['pass', 'fail', 'fail', 'fail', 'pass', 'pass'] as $index => $outcome) {
                $at = $start->addMinutes($index);
                CarbonImmutable::setTestNow($at);
                app(MonitoringScheduler::class)->tick($org, $at);
                $run = JobRun::forOrganization($org)->where('state', 'queued')->where('scheduled_slot', $at->format('Y-m-d H:i:s'))->sole();
                (new ProbeMonitor($run->id))->handle(app(ObservationRecorder::class), app(IncidentEngine::class), $executor);
                $states[] = Monitor::forOrganization($org)->sole()->state;
            }
            $incident = Incident::forOrganization($org)->sole();
            app(IncidentActions::class)->change($incident, $owner, 'close', $incident->version, ['summary' => 'Fixture pulih; cause belum diketahui.']);
            if ($browser) {
                foreach ([6, 7, 8] as $index) {
                    $at = $start->addMinutes($index);
                    CarbonImmutable::setTestNow($at);
                    app(MonitoringScheduler::class)->tick($org, $at);
                    $run = JobRun::forOrganization($org)->where('state', 'queued')->where('scheduled_slot', $at->format('Y-m-d H:i:s'))->sole();
                    (new ProbeMonitor($run->id))->handle(app(ObservationRecorder::class), app(IncidentEngine::class), $executor);
                }
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
        $report = [
            'organization_id' => $org->id, 'project_ids' => array_map(fn ($project) => $project->id, $projects), 'canonical_monitors' => Monitor::forOrganization($org)->count(),
            'states' => $states, 'observations' => Observation::forOrganization($org)->count(), 'all_fake' => Observation::forOrganization($org)->where('fake', false)->doesntExist(),
            'incident_id' => $incident->id, 'incident_state' => $incident->fresh()->state, 'impacted_projects' => $incident->projects()->count(),
            'down_events' => OutboxEvent::forOrganization($org)->where('event_type', 'incident.opened')->count(), 'recovery_events' => OutboxEvent::forOrganization($org)->where('event_type', 'incident.resolved')->count(),
            'stale_health' => app(MonitoringHealth::class)->project($projects[0], $now->addMinutes(4))['health'], 'stale_scheduler' => app(RuntimeHealth::class)->snapshot($org, $now->addMinutes(4))['components']['scheduler']['state'],
            'hosting_credentials' => 'not_configured', 'native_probes' => 'live_unverified', 'telegram_delivery' => 'not_configured', 'scope' => 'M1 deterministic fake observation loop',
        ];
        if ($browser) {
            $path = storage_path('app/qa-m1-login.json');
            file_put_contents($path, json_encode(['email' => $owner->email, 'password' => $password, 'organization_id' => $org->id, 'incident_id' => Incident::forOrganization($org)->latest('id')->first()->id, 'project_id' => $projects[0]->id, 'asset_id' => $asset->id]));
            $report['browser_manifest'] = 'storage/app/qa-m1-login.json';
        }

        return $report;
    }
}
