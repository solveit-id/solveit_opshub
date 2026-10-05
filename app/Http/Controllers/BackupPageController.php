<?php

namespace App\Http\Controllers;

use App\Application\Backups\BackupArtifactAccess;
use App\Application\Backups\BackupRuns;
use App\Application\Connectors\ConnectorAccess;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\IdentityAccess\ProjectAccess;
use App\Models\BackupArtifact;
use App\Models\BackupPolicy;
use App\Models\BackupRetentionReport;
use App\Models\BackupRun;
use App\Models\Connector;
use App\Models\HostingAccount;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BackupPageController extends Controller
{
    public function index(Request $request, Organization $organization)
    {
        $actor = $request->user();
        $items = [];
        foreach (BackupPolicy::forOrganization($organization)->orderBy('id')->cursor() as $policy) {
            $account = HostingAccount::findOrFail($policy->hosting_account_id);
            try {
                app(ConnectorAccess::class)->requireAccount($organization, $actor, $account);
            } catch (HttpException|AuthorizationException) {
                continue;
            }
            $runs = [];
            foreach (BackupRun::where('hosting_account_id', $account->id)->latest('id')->limit(30)->get() as $run) {
                try {
                    app(BackupRuns::class)->requireRead($organization, $actor, $run);
                } catch (HttpException|AuthorizationException) {
                    continue;
                }
                $artifacts = BackupArtifact::where('backup_run_id', $run->id)->get(['id', 'organization_id', 'hosting_account_id', 'backup_run_id', 'artifact_reference', 'state', 'verification_level', 'coverage_scopes', 'source_observed_at', 'verified_at', 'encrypted_bytes', 'fake', 'legal_hold', 'restore_pending', 'version']);
                $runs[] = [...$run->only(['id', 'run_reference', 'state', 'source_status', 'transfer_status', 'integrity_status', 'reason_code', 'completed_at', 'impacted_project_ids', 'fake']), 'artifacts' => $artifacts,
                    'can_download' => app(OrganizationAuthorizationService::class)->can($actor, $organization, 'backup.download') && app(BackupArtifactAccess::class)->protection($run)];
            }
            $connector = Connector::findOrFail($policy->connector_id);
            $goods = DB::table('backup_scope_goods')->join('backup_artifacts', 'backup_artifacts.id', '=', 'backup_scope_goods.backup_artifact_id')->where('backup_scope_goods.hosting_account_id', $account->id)->get(['scope', 'backup_scope_goods.source_observed_at', 'verification_level']);
            // Only expose goodness whose historical run remains visible, even after account usage changes.
            $goods = $goods->filter(function ($good) use ($organization, $actor, $account): bool {
                $id = DB::table('backup_scope_goods')->where('hosting_account_id', $account->id)->where('scope', $good->scope)->value('backup_artifact_id');
                try {
                    app(BackupArtifactAccess::class)->require($organization, $actor, BackupArtifact::findOrFail($id));

                    return true;
                } catch (HttpException|AuthorizationException) {
                    return false;
                }
            })->map(function ($good) {
                $good->source_observed_at = CarbonImmutable::parse($good->source_observed_at, 'UTC')->toIso8601String();

                return $good;
            })->values();
            $items[] = ['id' => $policy->id, 'version' => $policy->version, 'enabled' => $policy->enabled, 'account_id' => $account->id, 'connector_kind' => $connector->kind,
                'validation_state' => $connector->validation_state, 'required_scopes' => $policy->configuration['required_scopes'], 'timezone' => $policy->configuration['timezone'],
                'daily_at' => $policy->configuration['daily_at'], 'jitter_minutes' => $policy->configuration['jitter_minutes'], 'rpo_hours' => $policy->configuration['rpo_hours'],
                'retention' => $policy->configuration['retention'], 'cleanup_temporary_source' => $policy->configuration['cleanup_temporary_source'] ?? false, 'last_goods' => $goods, 'runs' => $runs,
                'retention_reports' => app(ProjectAccess::class)->owner($actor, $organization) ? BackupRetentionReport::where('backup_policy_id', $policy->id)->latest('id')->limit(10)->get(['id', 'state', 'policy_version', 'results', 'expires_at']) : []];
        }
        $control = DB::table('backup_write_controls')->where('organization_id', $organization->id)->first();
        $data = ['organization' => $organization->only(['id', 'name']), 'items' => $items, 'isOwner' => app(ProjectAccess::class)->owner($actor, $organization),
            'canRun' => app(OrganizationAuthorizationService::class)->can($actor, $organization, 'backup.run'), 'control' => ['paused' => (bool) ($control?->paused ?? true), 'version' => $control?->version ?? 0],
            'liveEnabled' => (bool) config('opshub.live_connectors_enabled')];

        return $request->is('api/*') ? response()->json(['data' => $data]) : Inertia::render('Backups/Index', $data);
    }
}
