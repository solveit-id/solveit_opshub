<?php

namespace App\Http\Middleware;

use App\Application\IdentityAccess\ProjectAccess;
use App\Models\Asset;
use App\Models\AssetUsage;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Environment;
use App\Models\HostingAccount;
use App\Models\ManagementAuthorization;
use App\Models\Project;
use App\Models\ServiceSubscription;
use Closure;
use Illuminate\Http\Request;

class EnsureRegistryProjectScope
{
    public function handle(Request $request, Closure $next)
    {
        $org = $request->route('organization');
        $access = app(ProjectAccess::class);
        if ($access->owner($request->user(), $org)) {
            return $next($request);
        }
        $visible = $access->query($request->user(), $org)->pluck('id');
        $models = array_filter($request->route()->parameters(), fn ($model) => is_object($model) && $model !== $org);
        foreach (['project_id' => Project::class, 'environment_id' => Environment::class, 'asset_id' => Asset::class, 'client_id' => Client::class] as $field => $class) {
            if ($request->filled($field)) {
                $models[] = $class::findOrFail($request->input($field));
            }
        }
        foreach ($models as $model) {
            abort_unless($model->organization_id === $org->id, 404);
            $projects = match (true) {
                $model instanceof Project => collect([$model->id]),
                $model instanceof Environment, $model instanceof AssetUsage => collect([$model->project_id]),
                $model instanceof Client => $model->projects()->pluck('id'),
                $model instanceof Contact => Project::where('client_id', $model->client_id)->pluck('id'),
                $model instanceof Asset => $model->usages()->pluck('project_id')->unique(),
                $model instanceof HostingAccount, $model instanceof ServiceSubscription => AssetUsage::where('asset_id', $model->asset_id)->pluck('project_id')->unique(),
                $model instanceof ManagementAuthorization => $model->resource_type === 'project' ? collect([$model->resource_id]) : collect([]),
                default => collect([]),
            };
            abort_unless($projects->isNotEmpty() && $projects->diff($visible)->isEmpty(), 404);
        }
        if (! $request->isMethod('GET') && $models === []) {
            abort(403, 'Pembuatan resource baru memerlukan Owner; operator mengelola proyek ditugaskan.');
        }

        return $next($request);
    }
}
