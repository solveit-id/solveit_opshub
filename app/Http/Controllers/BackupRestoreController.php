<?php

namespace App\Http\Controllers;

use App\Application\Backups\BackupIssues;
use App\Application\Backups\RestoreDrills;
use App\Models\BackupArtifact;
use App\Models\BackupInternalIncident;
use App\Models\BackupRestoreDrill;
use App\Models\Organization;
use Illuminate\Http\Request;

class BackupRestoreController extends Controller
{
    public function enqueue(Request $request, Organization $organization, BackupArtifact $artifact)
    {
        $data = $request->validate(['target_reference' => ['required', 'string', 'max:128'], 'target_kind' => ['required', 'in:isolated']]);

        return response()->json(['data' => app(RestoreDrills::class)->enqueue($organization, $request->user(), $artifact, $data['target_reference'], $data['target_kind'])], 202);
    }

    public function reconcile(Request $request, Organization $organization, BackupRestoreDrill $drill)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => app(RestoreDrills::class)->reconcile($organization, $request->user(), $drill, $data['version'])]);
    }

    public function manual(Request $request, Organization $organization, BackupRestoreDrill $drill)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'evidence_reference' => ['required', 'string', 'max:128'],
            'checks' => ['required', 'array:target_isolated,production_overwrite,files_passed,database_passed'], 'checks.target_isolated' => ['required', 'boolean'],
            'checks.production_overwrite' => ['required', 'boolean'], 'checks.files_passed' => ['required', 'boolean'], 'checks.database_passed' => ['required', 'boolean']]);

        return response()->json(['data' => app(RestoreDrills::class)->recordManual($organization, $request->user(), $drill, $data['version'], $data['checks'], $data['evidence_reference'])]);
    }

    public function resolve(Request $request, Organization $organization, BackupInternalIncident $issue)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'evidence_reference' => ['required', 'string', 'max:128']]);

        return response()->json(['data' => app(BackupIssues::class)->resolve($organization, $request->user(), $issue, $data['version'], $data['evidence_reference'])]);
    }
}
