<?php

namespace App\Listeners;

use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\Webhook\WebhookEventType;

class DispatchBookingCancelledWebhooks extends DispatchesWebhookDeliveries
{
    public function handle(BookingCancelled $event): void
    {
        $this->dispatchFor(WebhookEventType::BookingCancelled, $event->payload);
    }
}
