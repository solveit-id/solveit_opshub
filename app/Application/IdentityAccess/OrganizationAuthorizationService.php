<?php

namespace App\Application\IdentityAccess;

use App\Domain\IdentityAccess\Role;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class OrganizationAuthorizationService
{
    public function membership(User $user, Organization $organization): ?Membership
    {
        if (! $user->is_active || ! $organization->is_active) {
            return null;
        }

        return Membership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();
    }

    public function can(User $user, Organization $organization, string $ability): bool
    {
        $membership = $this->membership($user, $organization);

        if ($membership === null) {
            return false;
        }

        if ($membership->role->can($ability)) {
            return true;
        }

        return in_array($ability, $membership->extra_permissions ?? [], true);
    }

    public function require(User $user, Organization $organization, string $ability): void
    {
        if (! $this->can($user, $organization, $ability)) {
            throw new AuthorizationException('You do not have access to this organization resource.');
        }
    }

    public function isOwner(User $user): bool
    {
        return Membership::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('role', Role::Owner->value)
            ->exists();
    }
}
