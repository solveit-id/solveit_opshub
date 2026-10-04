<?php

namespace App\Application\Registry;

use App\Application\IdentityAccess\ProjectAccess;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

class RegistryPresenter
{
    public function index(Organization $organization, ?User $user = null): array
    {
        $visible = $user === null ? Project::forOrganization($organization) : app(ProjectAccess::class)->query($user, $organization);
        $ids = $visible->pluck('id');
        $owner = $user === null || app(ProjectAccess::class)->owner($user, $organization);

        return [
            'clients' => Client::query()
                ->forOrganization($organization)
                ->when(! $owner, fn ($query) => $query->whereHas('projects', fn ($query) => $query->whereIn('id', $ids)))
                ->withCount(['projects', 'contacts'])
                ->orderBy('name')
                ->get(['id', 'name', 'status', 'notes', 'version']),
            'projects' => Project::query()
                ->forOrganization($organization)
                ->whereIn('id', $ids)
                ->with(['client:id,name', 'environments:id,project_id,kind,display_name'])
                ->orderBy('name')
                ->get(['id', 'client_id', 'code', 'name', 'lifecycle', 'criticality', 'stack_tags', 'internal_pic_user_id', 'notes', 'version']),
            'assets' => Asset::query()
                ->forOrganization($organization)
                ->when(! $owner, fn ($query) => $query->whereHas('usages', fn ($query) => $query->whereIn('project_id', $ids)))
                ->when($owner, fn ($query) => $query->with(['hostingAccount', 'serviceSubscription']))
                ->withCount(['usages' => fn ($query) => $query->whereIn('project_id', $ids)])
                ->orderBy('kind')
                ->orderBy('canonical_identity')
                ->get(['id', 'kind', 'canonical_identity', 'responsibility', 'owner_user_id', 'source', 'verified_at', 'notes', 'version']),
        ];
    }

    public function project(Organization $organization, Project $project, ?User $user = null): array
    {
        return [
            'project' => $project->load([
                'client:id,name,status',
                'environments',
                'assetUsages.asset:id,kind,canonical_identity,responsibility,source,verified_at',
                'assetUsages.environment:id,kind,display_name',
            ]),
            'availableAssets' => Asset::query()
                ->forOrganization($organization)
                ->when($user !== null && ! app(ProjectAccess::class)->owner($user, $organization), fn ($query) => $query->whereHas('usages', fn ($query) => $query->whereIn('project_id', app(ProjectAccess::class)->query($user, $organization)->select('id'))))
                ->orderBy('kind')
                ->orderBy('canonical_identity')
                ->get(['id', 'kind', 'canonical_identity']),
        ];
    }
}
