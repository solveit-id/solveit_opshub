<?php

namespace App\Http\Middleware;

use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationAccess
{
    public function __construct(private readonly OrganizationAuthorizationService $authorization) {}

    public function handle(Request $request, Closure $next, string $ability = 'organization.read'): Response
    {
        $organization = $request->route('organization');

        if (! $organization instanceof Organization || $request->user() === null) {
            abort(404);
        }

        $this->authorization->require($request->user(), $organization, $ability);

        return $next($request);
    }
}
