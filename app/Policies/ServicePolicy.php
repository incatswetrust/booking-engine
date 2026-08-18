<?php

namespace App\Policies;

use App\Domain\Auth\Permission;
use App\Domain\Organization\Organization;
use App\Domain\Service\Service;
use App\Models\User;

class ServicePolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesRead, $organization);
    }

    public function view(User $user, Service $service): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesRead, $service->organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesCreate, $organization);
    }

    public function update(User $user, Service $service): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesUpdate, $service->organization);
    }

    public function delete(User $user, Service $service): bool
    {
        return $user->hasPermissionTo(Permission::ResourcesDelete, $service->organization);
    }
}
