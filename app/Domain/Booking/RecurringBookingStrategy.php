<?php

namespace App\Domain\Booking;

/**
 * §72.
 */
enum RecurringBookingStrategy: string
{
    /**
     * Every occurrence must be available, or none are created.
     */
    case AllOrNothing = 'all_or_nothing';

    /**
     * Create whichever occurrences are available; report the rest as
     * skipped instead of failing the whole request.
     */
    case BookAvailable = 'book_available';
}
