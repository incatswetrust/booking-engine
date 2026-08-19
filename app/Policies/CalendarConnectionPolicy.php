<?php

namespace App\Policies;

use App\Domain\Auth\Permission;
use App\Domain\Calendar\CalendarConnection;
use App\Domain\Resource\Resource;
use App\Models\User;

/**
 * Reuses integrations.manage, same as API Keys and Webhook Endpoints --
 * connecting an external calendar is Owner-only, credential-bearing.
 */
class CalendarConnectionPolicy
{
    public function view(User $user, Resource $resource): bool
    {
        return $user->hasPermissionTo(Permission::IntegrationsManage, $resource->organization);
    }

    public function connect(User $user, Resource $resource): bool
    {
        return $user->hasPermissionTo(Permission::IntegrationsManage, $resource->organization);
    }

    public function disconnect(User $user, CalendarConnection $connection): bool
    {
        return $user->hasPermissionTo(Permission::IntegrationsManage, $connection->resource->organization);
    }
}
