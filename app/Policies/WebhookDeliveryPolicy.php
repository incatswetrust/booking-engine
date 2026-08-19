<?php

namespace App\Policies;

use App\Domain\Auth\Permission;
use App\Domain\Webhook\WebhookDelivery;
use App\Models\User;

class WebhookDeliveryPolicy
{
    public function view(User $user, WebhookDelivery $delivery): bool
    {
        return $user->hasPermissionTo(Permission::IntegrationsManage, $delivery->webhookEndpoint->organization);
    }

    public function retry(User $user, WebhookDelivery $delivery): bool
    {
        return $user->hasPermissionTo(Permission::IntegrationsManage, $delivery->webhookEndpoint->organization);
    }
}
