<?php

namespace App\Application\PolicyScheduling;

use App\Models\Organization;
use App\Models\PolicyAssignment;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class EffectiveMonitoringPolicyService
{
    public function preview(Organization $organization, Project $project, ?CarbonImmutable $now = null): array
    {
        $assignment = PolicyAssignment::query()
            ->forOrganization($organization)
            ->where('resource_type', 'project')
            ->where('resource_id', $project->id)
            ->where('is_active', true)
            ->with('policyVersion')
            ->latest('id')
            ->first();

        if ($assignment === null) {
            return [
                'state' => 'not_configured',
                'coverage' => 'unconfigured',
                'reason' => 'Proyek belum memiliki policy monitoring aktif.',
                'checks' => [],
            ];
        }

        $configuration = $this->applyOverrides($assignment->policyVersion->configuration, $assignment->overrides ?? []);
        $assets = $project->assetUsages()->with(['asset', 'environment'])->get();
        $checks = [];
        $requirements = [
            'http' => fn () => $assets->contains(fn ($usage) => $usage->purpose === 'public_endpoint' && $usage->asset->kind === 'url' && $usage->environment?->kind === 'production'),
            'tls' => fn () => $assets->contains(fn ($usage) => $usage->purpose === 'public_endpoint' && $usage->asset->kind === 'url' && $usage->environment?->kind === 'production'),
            'dns' => fn () => $assets->contains(fn ($usage) => $usage->purpose === 'dns' && $usage->asset->kind === 'domain'),
        ];
        $clock = ($now ?? CarbonImmutable::now('UTC'))->setTimezone($configuration['timezone']);

        foreach ($configuration['checks'] as $kind => $setting) {
            if (! $setting['enabled']) {
                $checks[$kind] = ['state' => 'disabled', 'interval_seconds' => $setting['interval_seconds']];

                continue;
            }
            $hasRequirement = $requirements[$kind]();
            $checks[$kind] = [
                'state' => $hasRequirement ? 'configured' : 'not_configured',
                'interval_seconds' => $setting['interval_seconds'],
                'next_due_at' => $clock->addSeconds($setting['interval_seconds'])->toIso8601String(),
            ];
        }

        $enabled = collect($checks)->where('state', '!=', 'disabled');
        $coverage = $enabled->isEmpty() ? 'unconfigured' : ($enabled->every(fn ($check) => $check['state'] === 'configured') ? 'complete' : 'partial');

        return [
            'state' => $project->lifecycle === 'active' ? 'configured' : 'disabled',
            'coverage' => $coverage,
            'policy_version_id' => $assignment->policy_version_id,
            'timezone' => $configuration['timezone'],
            'overrides' => $assignment->overrides ?? [],
            'checks' => $checks,
        ];
    }

    public function validateOverrides(array $base, array $overrides): void
    {
        if (array_diff(array_keys($overrides), ['checks']) !== []) {
            throw ValidationException::withMessages(['overrides' => 'Override hanya dapat mengubah checks yang telah didefinisikan.']);
        }
        foreach ($overrides['checks'] ?? [] as $check => $override) {
            if (! isset($base['checks'][$check]) || ! is_array($override) || array_diff(array_keys($override), ['enabled', 'interval_seconds']) !== []) {
                throw ValidationException::withMessages(['overrides.checks' => 'Override check tidak valid.']);
            }
            if (isset($override['enabled']) && ! is_bool($override['enabled'])) {
                throw ValidationException::withMessages(["overrides.checks.{$check}.enabled" => 'enabled harus boolean.']);
            }
            if (isset($override['interval_seconds']) && (! is_int($override['interval_seconds']) || $override['interval_seconds'] < $base['checks'][$check]['interval_seconds'])) {
                throw ValidationException::withMessages(["overrides.checks.{$check}.interval_seconds" => 'Override tidak boleh mempercepat interval policy.']);
            }
        }
    }

    private function applyOverrides(array $base, array $overrides): array
    {
        foreach ($overrides['checks'] ?? [] as $check => $override) {
            $base['checks'][$check] = [...$base['checks'][$check], ...$override];
        }

        return $base;
    }
}
