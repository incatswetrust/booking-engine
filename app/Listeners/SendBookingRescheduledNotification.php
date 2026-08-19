<?php

namespace App\Listeners;

use App\Domain\Booking\Events\BookingRescheduled;
use App\Models\User;
use App\Notifications\BookingRescheduledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendBookingRescheduledNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(BookingRescheduled $event): void
    {
        $customer = User::where('public_id', $event->payload['customer_id'])->first();

        $customer?->notify(new BookingRescheduledNotification($event->payload));
    }
}
