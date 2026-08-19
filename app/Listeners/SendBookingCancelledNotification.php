<?php

namespace App\Listeners;

use App\Domain\Booking\Events\BookingCancelled;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendBookingCancelledNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(BookingCancelled $event): void
    {
        $customer = User::where('public_id', $event->payload['customer_id'])->first();

        $customer?->notify(new BookingCancelledNotification($event->payload));
    }
}
