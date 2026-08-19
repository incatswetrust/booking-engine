<?php

namespace App\Policies;

use App\Domain\Auth\Permission;
use App\Domain\Organization\Organization;
use App\Domain\Webhook\WebhookEndpoint;
use App\Models\User;

/**
 * Reuses integrations.manage, same as API Keys -- §6 covers webhook
 * endpoints and API keys under the same generic "integrations"
 * permission, and both are Owner-only, powerful, credential-bearing
 * resources.
 */
class WebhookEndpointPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::IntegrationsManage, $organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermissionTo(Permission::IntegrationsManage, $organization);
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->hasPermissionTo(Permission::IntegrationsManage, $endpoint->organization);
    }

    public function delete(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->hasPermissionTo(Permission::IntegrationsManage, $endpoint->organization);
    }
}
