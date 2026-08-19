<?php

namespace App\Listeners;

use App\Domain\Booking\Events\BookingCreated;
use App\Domain\Webhook\WebhookEventType;

class DispatchBookingCreatedWebhooks extends DispatchesWebhookDeliveries
{
    public function handle(BookingCreated $event): void
    {
        $this->dispatchFor(WebhookEventType::BookingCreated, $event->payload);
    }
}
