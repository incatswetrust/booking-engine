<?php

namespace App\Policies;

use App\Domain\Auth\Permission;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Models\User;

class LocationPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesRead, $organization);
    }

    public function view(User $user, Location $location): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesRead, $location->organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesCreate, $organization);
    }

    public function update(User $user, Location $location): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesUpdate, $location->organization);
    }

    public function delete(User $user, Location $location): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesDelete, $location->organization);
    }
}
