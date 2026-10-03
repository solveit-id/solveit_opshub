<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureOrganizationAccess;
use App\Http\Middleware\EnsureOwnerMfa;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireRecentStepUp;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureActiveUser::class,
        ]);
        $middleware->alias([
            'active.user' => EnsureActiveUser::class,
            'organization.access' => EnsureOrganizationAccess::class,
            'owner.mfa' => EnsureOwnerMfa::class,
            'step-up' => RequireRecentStepUp::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
