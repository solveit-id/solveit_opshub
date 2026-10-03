<?php

use App\Http\Controllers\FoundationOrganizationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active.user', 'organization.access:organization.read'])
    ->get('/v1/foundation/organizations/{organization}', [FoundationOrganizationController::class, 'show'])
    ->name('foundation.organizations.show');
