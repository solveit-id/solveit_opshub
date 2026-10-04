<?php

namespace App\Application\Monitoring;

use App\Infrastructure\Monitoring\DnsProbe;
use App\Infrastructure\Monitoring\HttpProbe;
use App\Infrastructure\Monitoring\ProbeResult;
use App\Infrastructure\Monitoring\TlsProbe;
use App\Models\Asset;
use App\Models\Monitor;

class ProbeExecutor
{
    public function execute(Monitor $monitor): ProbeResult
    {
        if (! config('opshub.public_probes_enabled')) {
            return new ProbeResult('unknown', 'PUBLIC_PROBES_DISABLED');
        }
        $asset = Asset::forOrganization($monitor->organization_id)->findOrFail($monitor->asset_id);
        if (isset($monitor->configuration['asset_version']) && $monitor->configuration['asset_version'] !== $asset->version) {
            return new ProbeResult('unknown', 'TARGET_CONFIGURATION_CHANGED');
        }

        return match ($monitor->kind) {
            'http' => app(HttpProbe::class)->check($asset->canonical_identity, $monitor->configuration),
            'tls' => app(TlsProbe::class)->check($asset->canonical_identity, $monitor->configuration),
            'dns' => app(DnsProbe::class)->check($asset->canonical_identity, $monitor->configuration),
            default => new ProbeResult('unsupported', 'CHECK_UNSUPPORTED'),
        };
    }
}
