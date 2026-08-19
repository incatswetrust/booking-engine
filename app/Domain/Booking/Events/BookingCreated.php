<?php

namespace App\Domain\Booking\Events;

/**
 * Fired by the outbox relay (§33-35), not dispatched directly from
 * BookingService — the payload is whatever was durably recorded on the
 * outbox_messages row, not a live Eloquent model.
 */
class BookingCreated
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $bookingId,
        public readonly array $payload,
    ) {}
}
