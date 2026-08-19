<?php

namespace App\Listeners;

use App\Domain\Booking\Events\BookingRescheduled;

class SyncBookingRescheduledToCalendar extends SyncsBookingToCalendar
{
    public function handle(BookingRescheduled $event): void
    {
        $this->dispatchFor($event->payload, 'sync');
    }
}
