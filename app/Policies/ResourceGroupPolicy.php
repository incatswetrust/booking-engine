<?php

namespace App\Policies;

use App\Domain\Auth\Permission;
use App\Domain\Organization\Organization;
use App\Domain\Resource\ResourceGroup;
use App\Models\User;

class ResourceGroupPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesRead, $organization);
    }

    public function view(User $user, ResourceGroup $resourceGroup): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesRead, $resourceGroup->organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesCreate, $organization);
    }

    public function update(User $user, ResourceGroup $resourceGroup): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesUpdate, $resourceGroup->organization);
    }

    public function delete(User $user, ResourceGroup $resourceGroup): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesDelete, $resourceGroup->organization);
    }
}
