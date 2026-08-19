<?php

namespace App\Listeners;

use App\Domain\Payment\Events\PaymentCompleted;
use App\Domain\Webhook\WebhookEventType;

class DispatchPaymentCompletedWebhooks extends DispatchesWebhookDeliveries
{
    public function handle(PaymentCompleted $event): void
    {
        $this->dispatchFor(WebhookEventType::PaymentCompleted, $event->payload);
    }
}
