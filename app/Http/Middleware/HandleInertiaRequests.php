<?php

namespace App\Http\Middleware;

use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Domain\IdentityAccess\Role;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $organization = $request->user()?->memberships()
            ->where('is_active', true)
            ->with('organization:id,name,timezone,is_active')
            ->get()
            ->map(fn ($membership) => $membership->organization)
            ->first(fn ($candidate) => $candidate?->is_active);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'organization' => $organization?->only(['id', 'name', 'timezone']),
            'canConfigureTelegram' => $organization && app(OrganizationAuthorizationService::class)->membership($request->user(), $organization)?->role === Role::Owner,
        ];
    }
}
