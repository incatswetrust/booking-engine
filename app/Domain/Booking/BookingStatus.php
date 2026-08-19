<?php

namespace App\Domain\Booking;

enum BookingStatus: string
{
    case Pending = 'pending';
    case Held = 'held';
    case AwaitingPayment = 'awaiting_payment';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Expired = 'expired';

    /**
     * Statuses that still occupy the resource's calendar — mirrors the
     * WHERE clause on the bookings_no_overlap exclusion constraint.
     *
     * @return array<int, self>
     */
    public static function active(): array
    {
        return [self::Pending, self::Held, self::AwaitingPayment, self::Confirmed, self::CheckedIn];
    }
}
