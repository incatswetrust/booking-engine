<?php

namespace App\Domain\Payment\Events;

/**
 * Fired by the outbox relay (§33-35), same as the Booking domain
 * events — the payload is whatever was durably recorded on the
 * outbox_messages row, not the live Stripe SDK object. Listened to by
 * App\Listeners\ProcessPaymentWebhook (§32).
 */
class StripeWebhookReceived
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $webhookEventId,
        public readonly array $payload,
    ) {}
}
