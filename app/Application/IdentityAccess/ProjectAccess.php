<?php

namespace App\Application\IdentityAccess;

use App\Domain\IdentityAccess\Role;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ProjectAccess
{
    public function owner(User $user, Organization $organization): bool
    {
        return app(OrganizationAuthorizationService::class)->membership($user, $organization)?->role === Role::Owner;
    }

    public function query(User $user, Organization $organization): Builder
    {
        $query = Project::forOrganization($organization);
        if (app(OrganizationAuthorizationService::class)->membership($user, $organization) === null) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->owner($user, $organization)) {
            return $query;
        }

        return $query->where(fn ($query) => $query->where('internal_pic_user_id', $user->id)->orWhereIn('id', DB::table('project_accesses')->where('organization_id', $organization->id)->where('user_id', $user->id)->select('project_id')));
    }

    public function requireProject(User $user, Organization $organization, Project $project): void
    {
        abort_unless($this->query($user, $organization)->whereKey($project->id)->exists(), 404);
    }

    public function requireMonitor(User $user, Organization $organization, Monitor $monitor, bool $all = false): void
    {
        abort_unless($monitor->organization_id === $organization->id, 404);
        if ($this->owner($user, $organization)) {
            return;
        }
        $projects = $monitor->projects()->pluck('projects.id');
        $visible = $this->query($user, $organization)->whereIn('id', $projects)->count();
        abort_unless($visible > 0 && (! $all || $visible === $projects->count()), 404);
    }

    public function requireIncident(User $user, Organization $organization, Incident $incident, bool $all = false): void
    {
        abort_unless($incident->organization_id === $organization->id, 404);
        if ($this->owner($user, $organization)) {
            return;
        }
        $ids = $incident->projects()->pluck('projects.id');
        $visible = $this->query($user, $organization)->whereIn('id', $ids)->count();
        abort_unless($visible > 0 && (! $all || $visible === $ids->count()), 404);
    }
}
