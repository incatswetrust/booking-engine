<?php

namespace App\Domain\Payment\Events;

/**
 * Fired by the outbox relay (§33-35), same as the Booking domain
 * events -- the payload is whatever was durably recorded on the
 * outbox_messages row. Drives outbound webhook delivery for the
 * payment.completed subscription (§41), via DispatchWebhookDeliveries.
 */
class PaymentCompleted
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly array $payload,
    ) {}
}
