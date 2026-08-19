<?php

namespace App\Domain\Webhook;

enum WebhookEndpointStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
