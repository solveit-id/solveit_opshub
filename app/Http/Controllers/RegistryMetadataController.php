<?php

namespace App\Http\Controllers;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Http\Requests\Registry\HostingAccountRequest;
use App\Http\Requests\Registry\ManagementAuthorizationRequest;
use App\Http\Requests\Registry\ServiceSubscriptionRequest;
use App\Models\Asset;
use App\Models\Evidence;
use App\Models\HostingAccount;
use App\Models\ManagementAuthorization;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ServiceSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RegistryMetadataController extends Controller
{
    public function __construct(
        private readonly OrganizationAuthorizationService $authorization,
        private readonly AuditWriter $audit,
    ) {}

    public function storeHostingAccount(HostingAccountRequest $request, Organization $organization): JsonResponse
    {
        $this->manage($request, $organization);
        $data = $request->validated();
        $asset = $this->asset($organization, $data['asset_id'], 'hosting_account');
        if (HostingAccount::query()->forOrganization($organization)->where('asset_id', $asset->id)->exists()) {
            throw ValidationException::withMessages(['asset_id' => 'Asset hosting ini sudah memiliki metadata account.']);
        }

        $account = HostingAccount::create(['organization_id' => $organization->id, ...$data]);
        $this->audit($request, $organization, 'registry.hosting_account.created', $account, [], $account->getAttributes());

        return response()->json(['data' => $account], 201);
    }

    public function updateHostingAccount(HostingAccountRequest $request, Organization $organization, HostingAccount $hostingAccount): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($hostingAccount, $organization);
        $data = $request->validated();
        $asset = $this->asset($organization, $data['asset_id'], 'hosting_account');
        if (HostingAccount::query()->forOrganization($organization)->where('asset_id', $asset->id)->where('id', '!=', $hostingAccount->id)->exists()) {
            throw ValidationException::withMessages(['asset_id' => 'Asset hosting ini sudah memiliki metadata account.']);
        }
        $before = $hostingAccount->getAttributes();
        $hostingAccount->update([...$data, 'version' => $hostingAccount->version + 1]);
        $this->audit($request, $organization, 'registry.hosting_account.updated', $hostingAccount, $before, $hostingAccount->getAttributes());

        return response()->json(['data' => $hostingAccount->fresh()]);
    }

    public function storeServiceSubscription(ServiceSubscriptionRequest $request, Organization $organization): JsonResponse
    {
        $this->manage($request, $organization);
        $data = $request->validated();
        $asset = $this->asset($organization, $data['asset_id'], 'service_subscription');
        $this->evidence($organization, $data['evidence_id'] ?? null);
        if (ServiceSubscription::query()->forOrganization($organization)->where('asset_id', $asset->id)->exists()) {
            throw ValidationException::withMessages(['asset_id' => 'Asset layanan ini sudah memiliki metadata subscription.']);
        }

        $subscription = ServiceSubscription::create(['organization_id' => $organization->id, ...$data]);
        $this->audit($request, $organization, 'registry.service_subscription.created', $subscription, [], $subscription->getAttributes());

        return response()->json(['data' => $subscription], 201);
    }

    public function updateServiceSubscription(ServiceSubscriptionRequest $request, Organization $organization, ServiceSubscription $serviceSubscription): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($serviceSubscription, $organization);
        $data = $request->validated();
        $asset = $this->asset($organization, $data['asset_id'], 'service_subscription');
        if (ServiceSubscription::query()->forOrganization($organization)->where('asset_id', $asset->id)->where('id', '!=', $serviceSubscription->id)->exists()) {
            throw ValidationException::withMessages(['asset_id' => 'Asset layanan ini sudah memiliki metadata subscription.']);
        }
        $this->evidence($organization, $data['evidence_id'] ?? null);
        $before = $serviceSubscription->getAttributes();
        $serviceSubscription->update([...$data, 'version' => $serviceSubscription->version + 1]);
        $this->audit($request, $organization, 'registry.service_subscription.updated', $serviceSubscription, $before, $serviceSubscription->getAttributes());

        return response()->json(['data' => $serviceSubscription->fresh()]);
    }

    public function storeManagementAuthorization(ManagementAuthorizationRequest $request, Organization $organization): JsonResponse
    {
        $this->manage($request, $organization);
        $data = $request->validated();
        $this->authorizedResource($organization, $data['resource_type'], $data['resource_id']);
        $this->evidence($organization, $data['evidence_id'] ?? null);
        $authorization = ManagementAuthorization::create(['organization_id' => $organization->id, ...$data]);
        $this->audit($request, $organization, 'registry.management_authorization.created', $authorization, [], $authorization->getAttributes());

        return response()->json(['data' => $authorization], 201);
    }

    public function updateManagementAuthorization(ManagementAuthorizationRequest $request, Organization $organization, ManagementAuthorization $managementAuthorization): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($managementAuthorization, $organization);
        $data = $request->validated();
        $this->authorizedResource($organization, $data['resource_type'], $data['resource_id']);
        $this->evidence($organization, $data['evidence_id'] ?? null);
        $before = $managementAuthorization->getAttributes();
        $managementAuthorization->update($data);
        $this->audit($request, $organization, 'registry.management_authorization.updated', $managementAuthorization, $before, $managementAuthorization->getAttributes());

        return response()->json(['data' => $managementAuthorization->fresh()]);
    }

    private function manage(Request $request, Organization $organization): void
    {
        $this->authorization->require($request->user(), $organization, 'registry.manage');
    }

    private function asset(Organization $organization, int $assetId, string $kind): Asset
    {
        $asset = Asset::query()->forOrganization($organization)->findOrFail($assetId);
        if ($asset->kind !== $kind) {
            throw ValidationException::withMessages(['asset_id' => "Asset harus bertipe {$kind}."]);
        }

        return $asset;
    }

    private function evidence(Organization $organization, ?int $evidenceId): void
    {
        if ($evidenceId !== null) {
            Evidence::query()->forOrganization($organization)->findOrFail($evidenceId);
        }
    }

    private function authorizedResource(Organization $organization, string $resourceType, int $resourceId): void
    {
        match ($resourceType) {
            'project' => Project::query()->forOrganization($organization)->findOrFail($resourceId),
            'hosting_account' => HostingAccount::query()->forOrganization($organization)->findOrFail($resourceId),
        };
    }

    private function within(object $model, Organization $organization): void
    {
        abort_unless($model->organization_id === $organization->id, 404);
    }

    private function audit(Request $request, Organization $organization, string $action, object $model, array $before, array $after): void
    {
        $this->audit->write(
            $organization,
            $action,
            class_basename($model),
            $model->id,
            'success',
            $request->user(),
            $before,
            $after,
            ['ability' => 'registry.manage'],
            requestId: $request->header('X-Request-ID'),
        );
    }
}
