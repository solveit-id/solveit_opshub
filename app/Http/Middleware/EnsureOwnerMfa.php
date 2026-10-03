<?php

namespace App\Http\Middleware;

use App\Application\IdentityAccess\OrganizationAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwnerMfa
{
    public function __construct(private readonly OrganizationAuthorizationService $authorization) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (config('opshub.require_owner_mfa') && $user !== null && $this->authorization->isOwner($user) && $user->mfa_enabled_at === null) {
            abort(403, 'Owner MFA enrollment is required.');
        }

        return $next($request);
    }
}
