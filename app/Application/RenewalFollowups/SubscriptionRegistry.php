<?php

namespace App\Application\RenewalFollowups;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Models\Asset;
use App\Models\Evidence;
use App\Models\Organization;
use App\Models\RenewalCycle;
use App\Models\ServiceSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionRegistry
{
    public function save(Organization $org, User $actor, array $data, ?ServiceSubscription $service = null): ServiceSubscription
    {
        app(OrganizationAuthorizationService::class)->require($actor, $org, 'registry.manage');
        $scope = $service ?? new ServiceSubscription(['organization_id' => $org->id, 'asset_id' => $data['asset_id'], 'resource_asset_id' => $data['resource_asset_id'] ?? null]);
        app(RenewalAccess::class)->require($actor, $org, $scope, 'registry.manage');
        if (isset($data['resource_asset_id'])) {
            app(RenewalAccess::class)->require($actor, $org, new ServiceSubscription(['organization_id' => $org->id, 'asset_id' => $data['asset_id'], 'resource_asset_id' => $data['resource_asset_id']]), 'registry.manage');
        }

        return DB::transaction(function () use ($org, $actor, $data, $service): ServiceSubscription {
            $asset = Asset::forOrganization($org)->whereKey($data['asset_id'])->lockForUpdate()->firstOrFail();
            abort_unless($asset->kind === 'service_subscription', 422, 'Asset layanan wajib.');
            if ($service !== null) {
                $service = ServiceSubscription::forOrganization($org)->whereKey($service->id)->lockForUpdate()->firstOrFail();
                abort_unless($service->version === (int) ($data['version'] ?? 0), 409, 'Subscription berubah; muat ulang.');
                abort_unless($service->asset_id === $asset->id, 422, 'Canonical service tidak dapat dipindahkan.');
                if ($service->resource_asset_id !== null && array_key_exists('resource_asset_id', $data)) {
                    abort_unless($service->resource_asset_id === $data['resource_asset_id'], 422, 'Canonical resource tidak dapat dipindahkan/dilepas.');
                }
                if ($service->service_kind !== 'unknown' && isset($data['service_kind'])) {
                    abort_unless($service->service_kind === $data['service_kind'], 422, 'Jenis canonical layanan tidak dapat diganti.');
                }
            }
            if (ServiceSubscription::forOrganization($org)->where('asset_id', $asset->id)->when($service, fn ($q) => $q->where('id', '!=', $service->id))->exists()) {
                throw ValidationException::withMessages(['asset_id' => 'Canonical layanan sudah terdaftar.']);
            }
            if (! empty($data['resource_asset_id'])) {
                $resource = Asset::forOrganization($org)->whereKey($data['resource_asset_id'])->lockForUpdate()->firstOrFail();
                abort_unless(in_array($resource->kind, ['domain', 'hosting_account'], true), 422);
                if ($service && $service->resource_asset_id !== null) {
                    abort_unless($service->resource_asset_id === $resource->id, 422, 'Canonical resource tidak dapat dipindahkan.');
                }
                if (ServiceSubscription::forOrganization($org)->where('resource_asset_id', $resource->id)->where('service_kind', $data['service_kind'] ?? 'unknown')->when($service, fn ($q) => $q->where('id', '!=', $service->id))->exists()) {
                    throw ValidationException::withMessages(['resource_asset_id' => 'Resource shared sudah memiliki canonical subscription untuk jenis layanan ini.']);
                }
            }
            if (! empty($data['evidence_id'])) {
                Evidence::forOrganization($org)->findOrFail($data['evidence_id']);
            }
            $normalized = app(Expiry::class)->normalize($data);
            $before = $service?->getAttributes() ?? [];
            if ($service) {
                if (app(Expiry::class)->snapshot($service) !== [
                    'date_precision' => $normalized['date_precision'], 'source_timezone' => $normalized['source_timezone'] ?? null,
                    'source' => $normalized['source'], 'evidence_id' => $normalized['evidence_id'] ?? null,
                    'expiry_date' => $normalized['expiry_date'] ?? null, 'expires_at' => $normalized['expires_at'] ?? null,
                ]) {
                    if (trim($normalized['correction_reason'] ?? '') === '' || empty($normalized['evidence_id'])) {
                        throw ValidationException::withMessages(['correction_reason' => 'Koreksi expiry/source memerlukan reason dan evidence; gunakan verify renewal untuk perpanjangan.']);
                    }
                }
                $service->fill([...$normalized, 'version' => $service->version + 1])->save();
            } else {
                $service = ServiceSubscription::create([...$normalized, 'organization_id' => $org->id, 'version' => 1]);
            }
            $cycle = $this->cycle($service);
            // Metadata correction never resolves a cycle, changes payment, or certifies renewal.
            $cycle->update(['expiry_snapshot' => app(Expiry::class)->snapshot($service), 'version' => $cycle->version + 1]);
            app(AuditWriter::class)->write($org, $before === [] ? 'registry.service_subscription.created' : 'registry.service_subscription.updated', 'ServiceSubscription', $service->id, 'success', $actor, $before, $service->getAttributes(), reason: $data['correction_reason'] ?? null);

            return $service->fresh();
        });
    }

    public function cycle(ServiceSubscription $service): RenewalCycle
    {
        return DB::transaction(function () use ($service): RenewalCycle {
            $locked = ServiceSubscription::whereKey($service->id)->lockForUpdate()->firstOrFail();

            return RenewalCycle::firstOrCreate(['active_subscription_id' => $locked->id], [
                'organization_id' => $locked->organization_id, 'service_subscription_id' => $locked->id,
                'sequence' => (RenewalCycle::where('service_subscription_id', $locked->id)->max('sequence') ?? 0) + 1,
                'state' => 'active', 'expiry_snapshot' => app(Expiry::class)->snapshot($locked), 'opened_at' => now('UTC'),
            ]);
        });
    }
}
