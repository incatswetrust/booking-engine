<?php

namespace App\Policies;

use App\Domain\Auth\Permission;
use App\Domain\Organization\Organization;
use App\Models\User;

class OrganizationPolicy
{
    /**
     * Any member (regardless of role) can view the organization they belong to.
     */
    public function view(User $user, Organization $organization): bool
    {
        return $organization->roleFor($user) !== null;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::OrganizationsUpdate, $organization);
    }

    public function viewStatistics(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::AnalyticsRead, $organization);
    }
}
