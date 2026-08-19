<?php

namespace App\Listeners;

use App\Domain\Booking\Events\BookingCreated;

class SyncBookingCreatedToCalendar extends SyncsBookingToCalendar
{
    public function handle(BookingCreated $event): void
    {
        $this->dispatchFor($event->payload, 'sync');
    }
}
