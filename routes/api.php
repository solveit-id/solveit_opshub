<?php

use App\Application\RenewalFollowups\FollowupWorkflow;
use App\Http\Controllers\BackupArtifactController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BackupPageController;
use App\Http\Controllers\ClientTemplateController;
use App\Http\Controllers\ConnectorController;
use App\Http\Controllers\FollowupController;
use App\Http\Controllers\FoundationOrganizationController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\MonitoringPolicyController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RegistryController;
use App\Http\Controllers\RegistryMetadataController;
use App\Http\Controllers\RenewalPageController;
use App\Http\Controllers\TelegramBindingController;
use App\Http\Controllers\TelegramConfigurationController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\EnsureRegistryProjectScope;
use Illuminate\Support\Facades\Route;

// Dashboard APIs share the browser session and enforce CSRF on every mutation.
Route::post('/telegram/webhook/{bot}', TelegramWebhookController::class)->whereNumber('bot');
Route::middleware('web')->group(function (): void {
    Route::middleware(['auth', 'verified', 'owner.mfa', 'active.user', 'organization.access:organization.read'])
        ->prefix('/v1/organizations/{organization}')->group(function (): void {
            Route::get('/backups', [BackupPageController::class, 'index']);
            Route::middleware('step-up')->group(function (): void {
                Route::post('/backup-artifacts/{artifact}/download-link', [BackupArtifactController::class, 'issue']);
                Route::post('/backup-artifacts/{artifact}/hold', [BackupArtifactController::class, 'hold']);
                Route::post('/backup-artifacts/{artifact}/reconcile-deletion', [BackupArtifactController::class, 'reconcile']);
                Route::post('/backup-artifacts/{artifact}/cleanup-source', [BackupArtifactController::class, 'cleanupSource']);
                Route::post('/backup-policies/{policy}/retention', [BackupArtifactController::class, 'approveRetention']);
                Route::post('/backup-policies/{policy}/retention-preview', [BackupArtifactController::class, 'preview']);
                Route::post('/backup-retention-reports/{report}/apply', [BackupArtifactController::class, 'apply']);
            });
            Route::get('/backup-runs/{run}', [BackupController::class, 'show']);
            Route::post('/backup-runs/{run}/reconcile', [BackupController::class, 'reconcile'])->middleware('step-up');
            Route::post('/backup-policies', [BackupController::class, 'configure'])->middleware('step-up');
            Route::post('/backup-policies/{policy}/runs', [BackupController::class, 'enqueue'])->middleware('step-up');
            Route::post('/backup-write-control', [BackupController::class, 'pause'])->middleware('step-up');
        });
    Route::middleware(['auth', 'verified', 'owner.mfa', 'active.user', 'organization.access:organization.read'])
        ->prefix('/v1/organizations/{organization}/connectors')->group(function (): void {
            Route::get('/{connector}', [ConnectorController::class, 'show']);
            Route::post('/', [ConnectorController::class, 'configure'])->middleware('step-up');
            Route::post('/{connector}/test', [ConnectorController::class, 'test'])->middleware('step-up');
        });
    Route::middleware(['auth', 'verified', 'owner.mfa', 'active.user', 'organization.access:organization.read'])->prefix('/v1/organizations/{organization}/notifications')->group(function (): void {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/findings/{finding}/acknowledge', [NotificationController::class, 'acknowledge'])->middleware('step-up');
    });
    Route::middleware(['auth', 'verified', 'owner.mfa', 'active.user', 'organization.access:organization.read'])->prefix('/v1/organizations/{organization}/telegram-binding')->group(function (): void {
        Route::get('/', [TelegramBindingController::class, 'index']);
        Route::middleware('step-up')->group(function (): void {
            Route::post('/', [TelegramBindingController::class, 'start']);
            Route::post('/{intent}/confirm', [TelegramBindingController::class, 'confirm']);
            Route::delete('/', [TelegramBindingController::class, 'revoke']);
        });
    });
    Route::middleware(['auth', 'verified', 'owner.mfa', 'active.user', 'organization.access:organization.read'])->prefix('/v1/organizations/{organization}/telegram')->group(function (): void {
        Route::get('/', [TelegramConfigurationController::class, 'index']);
        Route::middleware('step-up')->group(function (): void {
            Route::put('/bot', [TelegramConfigurationController::class, 'bot']);
            Route::post('/identity', [TelegramConfigurationController::class, 'identity']);
            Route::post('/webhook', [TelegramConfigurationController::class, 'webhook']);
            Route::post('/destinations', [TelegramConfigurationController::class, 'destination']);
            Route::patch('/destinations/{destination}', [TelegramConfigurationController::class, 'destination']);
            Route::post('/destinations/{destination}/test', [TelegramConfigurationController::class, 'test']);
        });
    });
    Route::middleware(['auth', 'active.user', 'organization.access:organization.read'])->prefix('/v1/organizations/{organization}')->group(function (): void {
        Route::get('/renewals', [RenewalPageController::class, 'index']);
        Route::get('/follow-ups/{followup}', [RenewalPageController::class, 'show']);
        Route::post('/follow-ups/{followup}/drafts', [RenewalPageController::class, 'draft']);
        Route::post('/services/{serviceSubscription}/follow-up', [RenewalPageController::class, 'start']);
    });
    Route::middleware(['auth', 'active.user', 'organization.access:organization.read'])
        ->post('/v1/organizations/{organization}/follow-ups/{followup}/{action}', [FollowupController::class, 'action'])
        ->whereIn('action', [...FollowupWorkflow::ACTIONS, 'snooze']);
    Route::middleware(['auth', 'active.user', 'organization.access:organization.read'])
        ->prefix('/v1/organizations/{organization}/client-templates')->group(function (): void {
            Route::get('/', [ClientTemplateController::class, 'index']);
            Route::post('/{key}/preview', [ClientTemplateController::class, 'preview'])->where('key', 'TPL-\d{2}');
            Route::post('/{key}/publish', [ClientTemplateController::class, 'publish'])->where('key', 'TPL-\d{2}');
        });
    Route::middleware(['auth', 'active.user', 'organization.access:organization.read'])
        ->get('/v1/foundation/organizations/{organization}', [FoundationOrganizationController::class, 'show'])
        ->name('foundation.organizations.show');

    Route::middleware(['auth', 'active.user', 'organization.access:project.read'])
        ->prefix('/v1/organizations/{organization}/monitoring')->group(function (): void {
            Route::get('/overview', [MonitoringController::class, 'overview']);
            Route::get('/health', [MonitoringController::class, 'runtimeHealth']);
            Route::get('/incidents/{incident}', [MonitoringController::class, 'incident']);
            Route::post('/incidents/{incident}/{action}', [MonitoringController::class, 'action'])->whereIn('action', ['acknowledge', 'investigate', 'assign', 'close']);
            Route::post('/projects/{project}/access', [MonitoringController::class, 'grantAccess']);
        });

    Route::middleware(['auth', 'active.user', 'organization.access:project.read', EnsureRegistryProjectScope::class])
        ->prefix('/v1/organizations/{organization}/registry')
        ->group(function (): void {
            Route::get('/', [RegistryController::class, 'index'])->name('registry.index');

            Route::post('/clients', [RegistryController::class, 'storeClient']);
            Route::patch('/clients/{client}', [RegistryController::class, 'updateClient']);
            Route::delete('/clients/{client}', [RegistryController::class, 'destroyClient']);
            Route::post('/clients/{client}/contacts', [RegistryController::class, 'storeContact']);
            Route::patch('/contacts/{contact}', [RegistryController::class, 'updateContact']);
            Route::delete('/contacts/{contact}', [RegistryController::class, 'destroyContact']);

            Route::post('/projects', [RegistryController::class, 'storeProject']);
            Route::patch('/projects/{project}', [RegistryController::class, 'updateProject']);
            Route::delete('/projects/{project}', [RegistryController::class, 'archiveProject']);
            Route::post('/projects/{project}/environments', [RegistryController::class, 'storeEnvironment']);
            Route::patch('/environments/{environment}', [RegistryController::class, 'updateEnvironment']);
            Route::delete('/environments/{environment}', [RegistryController::class, 'destroyEnvironment']);

            Route::post('/assets', [RegistryController::class, 'storeAsset']);
            Route::patch('/assets/{asset}', [RegistryController::class, 'updateAsset']);
            Route::delete('/assets/{asset}', [RegistryController::class, 'destroyAsset']);
            Route::post('/assets/{asset}/usages', [RegistryController::class, 'storeAssetUsage']);
            Route::delete('/asset-usages/{usage}', [RegistryController::class, 'destroyAssetUsage']);

            Route::post('/hosting-accounts', [RegistryMetadataController::class, 'storeHostingAccount']);
            Route::patch('/hosting-accounts/{hostingAccount}', [RegistryMetadataController::class, 'updateHostingAccount']);
            Route::post('/service-subscriptions', [RegistryMetadataController::class, 'storeServiceSubscription']);
            Route::patch('/service-subscriptions/{serviceSubscription}', [RegistryMetadataController::class, 'updateServiceSubscription']);
            Route::post('/management-authorizations', [RegistryMetadataController::class, 'storeManagementAuthorization']);
            Route::patch('/management-authorizations/{managementAuthorization}', [RegistryMetadataController::class, 'updateManagementAuthorization']);

            Route::post('/monitoring-policies', [MonitoringPolicyController::class, 'store']);
            Route::patch('/monitoring-policies/{policy}', [MonitoringPolicyController::class, 'update']);
            Route::post('/monitoring-policies/{policy}/publish', [MonitoringPolicyController::class, 'publish']);
            Route::post('/monitoring-policy-versions/{policyVersion}/assignments', [MonitoringPolicyController::class, 'assign']);
            Route::get('/projects/{project}/effective-monitoring-policy', [MonitoringPolicyController::class, 'preview']);
        });
});
