<?php

namespace App\Policies;

use App\Domain\Auth\Permission;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Models\User;

class ResourcePolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesRead, $organization);
    }

    public function view(User $user, Resource $resource): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesRead, $resource->organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesCreate, $organization);
    }

    public function update(User $user, Resource $resource): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesUpdate, $resource->organization);
    }

    public function delete(User $user, Resource $resource): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesDelete, $resource->organization);
    }
}
