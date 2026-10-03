<?php

namespace App\Application\Registry;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;

class RegistryPresenter
{
    public function index(Organization $organization): array
    {
        return [
            'clients' => Client::query()
                ->forOrganization($organization)
                ->withCount(['projects', 'contacts'])
                ->orderBy('name')
                ->get(['id', 'name', 'status', 'notes', 'version']),
            'projects' => Project::query()
                ->forOrganization($organization)
                ->with(['client:id,name', 'environments:id,project_id,kind,display_name'])
                ->orderBy('name')
                ->get(['id', 'client_id', 'code', 'name', 'lifecycle', 'criticality', 'stack_tags', 'internal_pic_user_id', 'notes', 'version']),
            'assets' => Asset::query()
                ->forOrganization($organization)
                ->withCount('usages')
                ->orderBy('kind')
                ->orderBy('canonical_identity')
                ->get(['id', 'kind', 'canonical_identity', 'responsibility', 'owner_user_id', 'source', 'verified_at', 'notes', 'version']),
        ];
    }

    public function project(Organization $organization, Project $project): array
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
                ->orderBy('kind')
                ->orderBy('canonical_identity')
                ->get(['id', 'kind', 'canonical_identity']),
        ];
    }
}
