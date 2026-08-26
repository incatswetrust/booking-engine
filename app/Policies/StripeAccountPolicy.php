<?php

namespace App\Policies;

use App\Domain\Auth\Permission;
use App\Domain\Organization\Organization;
use App\Domain\Payment\StripeAccount;
use App\Models\User;

/**
 * Reuses integrations.manage, same as Calendar Connections, API Keys,
 * and Webhook Endpoints -- connecting a payment provider is
 * Owner-only, credential-bearing.
 */
class StripeAccountPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::IntegrationsManage, $organization);
    }

    public function connect(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::IntegrationsManage, $organization);
    }

    public function disconnect(User $user, StripeAccount $account): bool
    {
        return $user->hasPermissionTo(Permission::IntegrationsManage, $account->organization);
    }
}
