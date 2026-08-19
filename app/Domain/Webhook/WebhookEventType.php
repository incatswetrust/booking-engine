<?php

namespace App\Domain\Webhook;

/**
 * §41's subscribable event list. Distinct from the outbox's own
 * PascalCase event_type strings (BookingCreated, ...) -- see
 * DispatchWebhookDeliveries for the mapping between the two.
 */
enum WebhookEventType: string
{
    case BookingCreated = 'booking.created';
    case BookingConfirmed = 'booking.confirmed';
    case BookingCancelled = 'booking.cancelled';
    case PaymentCompleted = 'payment.completed';
}
