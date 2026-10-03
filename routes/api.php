<?php

use App\Http\Controllers\FoundationOrganizationController;
use App\Http\Controllers\RegistryController;
use App\Http\Controllers\RegistryMetadataController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.user', 'organization.access:organization.read'])
    ->get('/v1/foundation/organizations/{organization}', [FoundationOrganizationController::class, 'show'])
    ->name('foundation.organizations.show');

Route::middleware(['auth', 'active.user', 'organization.access:project.read'])
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
    });
