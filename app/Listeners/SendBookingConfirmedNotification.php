<?php

namespace App\Listeners;

use App\Domain\Booking\Events\BookingConfirmed;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * §35: BookingConfirmed -> SendConfirmationEmail (here, also Telegram —
 * see BookingNotification). Reached through the same outbox pipeline as
 * everything else, so it inherits that durability; this listener itself
 * also implements ShouldQueue on "notifications" (§61), the same
 * two-layer pattern as ProcessPaymentWebhook.
 */
class SendBookingConfirmedNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(BookingConfirmed $event): void
    {
        $customer = User::where('public_id', $event->payload['customer_id'])->first();

        $customer?->notify(new BookingConfirmedNotification($event->payload));
    }
}
