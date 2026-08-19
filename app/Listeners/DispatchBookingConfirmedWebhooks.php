<?php

namespace App\Listeners;

use App\Domain\Booking\Events\BookingConfirmed;
use App\Domain\Webhook\WebhookEventType;

class DispatchBookingConfirmedWebhooks extends DispatchesWebhookDeliveries
{
    public function handle(BookingConfirmed $event): void
    {
        $this->dispatchFor(WebhookEventType::BookingConfirmed, $event->payload);
    }
}
