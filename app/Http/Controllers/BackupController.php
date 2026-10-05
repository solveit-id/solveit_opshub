<?php

namespace App\Http\Controllers;

use App\Application\Backups\BackupPolicies;
use App\Application\Backups\BackupRuns;
use App\Application\Backups\CpanelBackupFlow;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Models\Connector;
use App\Models\Organization;
use Illuminate\Http\Request;

class BackupController extends Controller
{
    public function reconcile(Request $request, Organization $organization, BackupRun $run)
    {
        app(CpanelBackupFlow::class)->enqueueReconcile($organization, $request->user(), $run);

        return response()->json(['data' => ['state' => 'queued']], 202);
    }

    public function configure(Request $request, Organization $organization)
    {
        $data = $request->validate(['connector_id' => ['required', 'integer'], 'settings' => ['required', 'array'], 'enabled' => ['required', 'boolean'], 'version' => ['nullable', 'integer', 'min:1']]);
        $connector = Connector::forOrganization($organization)->findOrFail($data['connector_id']);
        $policy = app(BackupPolicies::class)->configure($organization, $request->user(), $connector, $data['settings'], $data['enabled'], $data['version'] ?? null);

        return response()->json(['data' => $policy], isset($data['version']) ? 200 : 201);
    }

    public function enqueue(Request $request, Organization $organization, BackupPolicy $policy)
    {
        abort_unless($policy->organization_id === $organization->id, 404);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key), 422);

        return response()->json(['data' => app(BackupRuns::class)->enqueue($organization, $request->user(), $policy, $data['version'], $key)], 202);
    }

    public function show(Request $request, Organization $organization, BackupRun $run)
    {
        abort_unless($run->organization_id === $organization->id, 404);
        app(BackupRuns::class)->requireRead($organization, $request->user(), $run);

        return response()->json(['data' => $run]);
    }

    public function pause(Request $request, Organization $organization)
    {
        $data = $request->validate(['paused' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:0']]);

        return response()->json(['data' => app(BackupPolicies::class)->setPaused($organization, $request->user(), $data['paused'], $data['version'])]);
    }
}
