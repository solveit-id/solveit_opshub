<?php

namespace App\Http\Controllers;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\Registry\ProjectLifecycleService;
use App\Application\Registry\RegistryPresenter;
use App\Http\Requests\Registry\AssetRequest;
use App\Http\Requests\Registry\AssetUsageRequest;
use App\Http\Requests\Registry\ClientRequest;
use App\Http\Requests\Registry\ContactRequest;
use App\Http\Requests\Registry\EnvironmentRequest;
use App\Http\Requests\Registry\ProjectRequest;
use App\Models\Asset;
use App\Models\AssetUsage;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Environment;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistryController extends Controller
{
    public function __construct(
        private readonly OrganizationAuthorizationService $authorization,
        private readonly AuditWriter $audit,
        private readonly ProjectLifecycleService $lifecycle,
        private readonly RegistryPresenter $presenter,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        return response()->json(['data' => $this->presenter->index($organization, $request->user())]);
    }

    public function storeClient(ClientRequest $request, Organization $organization): JsonResponse
    {
        $this->manage($request, $organization);
        $client = DB::transaction(function () use ($request, $organization): Client {
            $client = Client::create(['organization_id' => $organization->id, ...$request->validated()]);
            $this->audit($request, $organization, 'registry.client.created', $client, [], $client->getAttributes());

            return $client;
        });

        return response()->json(['data' => $client], 201);
    }

    public function updateClient(ClientRequest $request, Organization $organization, Client $client): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($client, $organization);
        $before = $client->getAttributes();
        $client->update([...$request->validated(), 'version' => $client->version + 1]);
        $this->audit($request, $organization, 'registry.client.updated', $client, $before, $client->getAttributes());

        return response()->json(['data' => $client->fresh()]);
    }

    public function destroyClient(Request $request, Organization $organization, Client $client): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($client, $organization);
        if ($client->projects()->exists()) {
            throw ValidationException::withMessages(['client' => 'Client yang masih memiliki proyek harus diarsipkan, bukan dihapus.']);
        }

        $before = $client->getAttributes();
        DB::transaction(function () use ($request, $organization, $client, $before): void {
            $client->delete();
            $this->audit($request, $organization, 'registry.client.deleted', $client, $before, []);
        });

        return response()->json(status: 204);
    }

    public function storeContact(ContactRequest $request, Organization $organization, Client $client): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($client, $organization);
        $contact = Contact::create(['organization_id' => $organization->id, 'client_id' => $client->id, ...$request->validated()]);
        $this->audit($request, $organization, 'registry.contact.created', $contact, [], $contact->getAttributes());

        return response()->json(['data' => $contact], 201);
    }

    public function updateContact(ContactRequest $request, Organization $organization, Contact $contact): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($contact, $organization);
        $before = $contact->getAttributes();
        $contact->update($request->validated());
        $this->audit($request, $organization, 'registry.contact.updated', $contact, $before, $contact->getAttributes());

        return response()->json(['data' => $contact->fresh()]);
    }

    public function destroyContact(Request $request, Organization $organization, Contact $contact): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($contact, $organization);
        $before = $contact->getAttributes();
        DB::transaction(function () use ($request, $organization, $contact, $before): void {
            $contact->delete();
            $this->audit($request, $organization, 'registry.contact.deleted', $contact, $before, []);
        });

        return response()->json(status: 204);
    }

    public function storeProject(ProjectRequest $request, Organization $organization): JsonResponse
    {
        $this->manage($request, $organization);
        $this->ensureMember($organization, $this->nullableInteger($request, 'internal_pic_user_id'));
        $project = DB::transaction(function () use ($request, $organization): Project {
            $project = Project::create(['organization_id' => $organization->id, ...$request->validated()]);
            $this->audit($request, $organization, 'registry.project.created', $project, [], $project->getAttributes());

            return $project;
        });

        return response()->json(['data' => $project], 201);
    }

    public function updateProject(ProjectRequest $request, Organization $organization, Project $project): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($project, $organization);
        $this->ensureMember($organization, $this->nullableInteger($request, 'internal_pic_user_id'));
        $before = $project->getAttributes();
        $project->update([...$request->validated(), 'version' => $project->version + 1]);
        $cancelled = $this->reconcileIfInactive($project->fresh());
        $this->audit($request, $organization, 'registry.project.updated', $project, $before, [...$project->getAttributes(), 'cancelled_future_jobs' => $cancelled]);

        return response()->json(['data' => $project->fresh(), 'meta' => ['cancelled_future_jobs' => $cancelled]]);
    }

    public function archiveProject(Request $request, Organization $organization, Project $project): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($project, $organization);
        $before = $project->getAttributes();
        $project->update(['lifecycle' => 'archived', 'version' => $project->version + 1]);
        $cancelled = $this->lifecycle->reconcileFutureWork($project->fresh());
        $this->audit($request, $organization, 'registry.project.archived', $project, $before, [...$project->getAttributes(), 'cancelled_future_jobs' => $cancelled]);

        return response()->json(['data' => $project->fresh(), 'meta' => ['cancelled_future_jobs' => $cancelled]]);
    }

    public function storeEnvironment(EnvironmentRequest $request, Organization $organization, Project $project): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($project, $organization);
        $data = $request->validated();
        if ($data['kind'] !== 'custom' && $project->environments()->where('kind', $data['kind'])->exists()) {
            throw ValidationException::withMessages(['kind' => 'Setiap proyek hanya dapat memiliki satu environment standar untuk setiap jenis.']);
        }

        $environment = Environment::create(['organization_id' => $organization->id, 'project_id' => $project->id, ...$data]);
        $this->audit($request, $organization, 'registry.environment.created', $environment, [], $environment->getAttributes());

        return response()->json(['data' => $environment], 201);
    }

    public function updateEnvironment(EnvironmentRequest $request, Organization $organization, Environment $environment): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($environment, $organization);
        $data = $request->validated();
        if ($data['kind'] !== 'custom' && Environment::query()->where('project_id', $environment->project_id)->where('kind', $data['kind'])->whereKeyNot($environment->id)->exists()) {
            throw ValidationException::withMessages(['kind' => 'Setiap proyek hanya dapat memiliki satu environment standar untuk setiap jenis.']);
        }

        $before = $environment->getAttributes();
        $environment->update([...$data, 'version' => $environment->version + 1]);
        $this->audit($request, $organization, 'registry.environment.updated', $environment, $before, $environment->getAttributes());

        return response()->json(['data' => $environment->fresh()]);
    }

    public function destroyEnvironment(Request $request, Organization $organization, Environment $environment): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($environment, $organization);
        if ($environment->assetUsages()->exists()) {
            throw ValidationException::withMessages(['environment' => 'Lepaskan relasi asset terlebih dahulu agar pemisahan environment tetap eksplisit.']);
        }

        $before = $environment->getAttributes();
        DB::transaction(function () use ($request, $organization, $environment, $before): void {
            $environment->delete();
            $this->audit($request, $organization, 'registry.environment.deleted', $environment, $before, []);
        });

        return response()->json(status: 204);
    }

    public function storeAsset(AssetRequest $request, Organization $organization): JsonResponse
    {
        $this->manage($request, $organization);
        $this->ensureMember($organization, $this->nullableInteger($request, 'owner_user_id'));
        $data = $request->validated();
        $this->ensureCanonicalAssetIsAvailable($organization, $data['kind'], $data['canonical_identity']);
        $asset = Asset::create(['organization_id' => $organization->id, ...$data]);
        $this->audit($request, $organization, 'registry.asset.created', $asset, [], $asset->getAttributes());

        return response()->json(['data' => $asset], 201);
    }

    public function updateAsset(AssetRequest $request, Organization $organization, Asset $asset): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($asset, $organization);
        $this->ensureMember($organization, $this->nullableInteger($request, 'owner_user_id'));
        $data = $request->validated();
        $this->ensureCanonicalAssetIsAvailable($organization, $data['kind'], $data['canonical_identity'], $asset->id);
        $before = $asset->getAttributes();
        $asset->update([...$data, 'version' => $asset->version + 1]);
        $this->audit($request, $organization, 'registry.asset.updated', $asset, $before, $asset->getAttributes());

        return response()->json(['data' => $asset->fresh()]);
    }

    public function destroyAsset(Request $request, Organization $organization, Asset $asset): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($asset, $organization);
        if ($asset->usages()->exists()) {
            throw ValidationException::withMessages(['asset' => 'Asset canonical yang masih dipakai proyek tidak dapat dihapus.']);
        }

        $before = $asset->getAttributes();
        DB::transaction(function () use ($request, $organization, $asset, $before): void {
            $asset->delete();
            $this->audit($request, $organization, 'registry.asset.deleted', $asset, $before, []);
        });

        return response()->json(status: 204);
    }

    public function storeAssetUsage(AssetUsageRequest $request, Organization $organization, Asset $asset): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($asset, $organization);
        $data = $request->validated();
        $project = Project::query()->forOrganization($organization)->findOrFail($data['project_id']);
        $environment = isset($data['environment_id']) ? Environment::query()->forOrganization($organization)->findOrFail($data['environment_id']) : null;
        if ($environment !== null && $environment->project_id !== $project->id) {
            throw ValidationException::withMessages(['environment_id' => 'Environment harus menjadi milik proyek yang menggunakan asset.']);
        }

        $usage = AssetUsage::firstOrCreate([
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'environment_id' => $environment?->id,
            'purpose' => $data['purpose'],
        ]);
        if ($usage->wasRecentlyCreated) {
            $this->audit($request, $organization, 'registry.asset_usage.created', $usage, [], $usage->getAttributes());
        }

        return response()->json(['data' => $usage, 'meta' => ['created' => $usage->wasRecentlyCreated]], $usage->wasRecentlyCreated ? 201 : 200);
    }

    public function destroyAssetUsage(Request $request, Organization $organization, AssetUsage $usage): JsonResponse
    {
        $this->manage($request, $organization);
        $this->within($usage, $organization);
        $before = $usage->getAttributes();
        DB::transaction(function () use ($request, $organization, $usage, $before): void {
            $usage->delete();
            $this->audit($request, $organization, 'registry.asset_usage.deleted', $usage, $before, []);
        });

        return response()->json(status: 204);
    }

    private function manage(Request $request, Organization $organization): void
    {
        $this->authorization->require($request->user(), $organization, 'registry.manage');
    }

    private function within(object $model, Organization $organization): void
    {
        abort_unless($model->organization_id === $organization->id, 404);
    }

    private function ensureMember(Organization $organization, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        if (! Membership::query()->where('organization_id', $organization->id)->where('user_id', $userId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['owner_user_id' => 'PIC atau owner asset harus merupakan anggota aktif organisasi.']);
        }
    }

    private function ensureCanonicalAssetIsAvailable(Organization $organization, string $kind, string $identity, ?int $ignoreId = null): void
    {
        $exists = Asset::query()
            ->forOrganization($organization)
            ->where('kind', $kind)
            ->where('canonical_identity', $identity)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['canonical_identity' => 'Asset canonical dengan jenis dan identitas ini sudah ada di organisasi. Tambahkan usage pada asset tersebut.']);
        }
    }

    private function reconcileIfInactive(Project $project): int
    {
        return in_array($project->lifecycle, ['paused', 'archived'], true)
            ? $this->lifecycle->reconcileFutureWork($project)
            : 0;
    }

    private function nullableInteger(Request $request, string $key): ?int
    {
        $value = $request->input($key);

        return $value === null ? null : (int) $value;
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
