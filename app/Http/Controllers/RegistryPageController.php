<?php

namespace App\Http\Controllers;

use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\Registry\RegistryPresenter;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistryPageController extends Controller
{
    public function __construct(
        private readonly RegistryPresenter $presenter,
        private readonly OrganizationAuthorizationService $authorization,
    ) {}

    public function index(Request $request, Organization $organization): Response
    {
        return Inertia::render('Registry/Index', [
            'organization' => $organization->only(['id', 'name', 'timezone']),
            'canManage' => $this->authorization->can($request->user(), $organization, 'registry.manage'),
            ...$this->presenter->index($organization),
        ]);
    }

    public function project(Request $request, Organization $organization, Project $project): Response
    {
        abort_unless($project->organization_id === $organization->id, 404);

        return Inertia::render('Registry/Project', [
            'organization' => $organization->only(['id', 'name', 'timezone']),
            'canManage' => $this->authorization->can($request->user(), $organization, 'registry.manage'),
            ...$this->presenter->project($organization, $project),
        ]);
    }
}
