<?php

namespace App\Policies;

use App\Domain\ApiKey\ApiKey;
use App\Domain\Auth\Permission;
use App\Domain\Organization\Organization;
use App\Models\User;

class ApiKeyPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::IntegrationsManage, $organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::IntegrationsManage, $organization);
    }

    public function delete(User $user, ApiKey $apiKey): bool
    {
        return $user->hasPermissionTo(Permission::IntegrationsManage, $apiKey->organization);
    }
}
