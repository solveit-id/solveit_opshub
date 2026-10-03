<?php

namespace App\Application\Registry;

use App\Models\JobRun;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ProjectLifecycleService
{
    /**
     * Stop only work that has not started. A leased/running worker is left for
     * later reconciliation so a lifecycle transition never kills work mid-run.
     */
    public function reconcileFutureWork(Project $project): int
    {
        return DB::transaction(function () use ($project): int {
            $environmentIds = $project->environments()->pluck('id');

            return JobRun::query()
                ->forOrganization($project->organization_id)
                ->where('state', 'queued')
                ->where('scheduled_slot', '>', now())
                ->where(function ($query) use ($project, $environmentIds): void {
                    $query->where(function ($projectQuery) use ($project): void {
                        $projectQuery->where('resource_type', 'project')
                            ->where('resource_id', $project->id);
                    })->orWhere(function ($environmentQuery) use ($environmentIds): void {
                        $environmentQuery->where('resource_type', 'environment')
                            ->whereIn('resource_id', $environmentIds);
                    });
                })
                ->update(['state' => 'cancelled', 'updated_at' => now()]);
        });
    }
}
