<?php

namespace App\Application\Connectors;

use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Application\IdentityAccess\ProjectAccess;
use App\Models\HostingAccount;
use App\Models\Organization;
use App\Models\User;

class ConnectorAccess
{
    public function requireAccount(Organization $org, User $user, HostingAccount $account, bool $manage = false): void
    {
        abort_unless($account->organization_id === $org->id && $account->asset->organization_id === $org->id, 404);
        $auth = app(OrganizationAuthorizationService::class);
        $auth->require($user, $org, $manage ? 'connector.manage' : 'project.read');
        $access = app(ProjectAccess::class);
        if ($access->owner($user, $org)) {
            return;
        }
        if ($manage) {
            // Connector management requires an explicit grant beyond the baseline Operator role.
            abort_unless(in_array('connector.manage', $auth->membership($user, $org)->extra_permissions ?? [], true), 403);
        }
        $ids = $account->asset->usages()->pluck('project_id')->unique();
        abort_unless($ids->isNotEmpty() && $access->query($user, $org)->whereIn('id', $ids)->count() === $ids->count(), 404);
    }
}
