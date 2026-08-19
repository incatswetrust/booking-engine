<?php

namespace App\Listeners;

use App\Domain\Booking\Events\BookingCancelled;

class SyncBookingCancelledToCalendar extends SyncsBookingToCalendar
{
    public function handle(BookingCancelled $event): void
    {
        $this->dispatchFor($event->payload, 'delete');
    }
}
