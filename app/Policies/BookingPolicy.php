<?php

namespace App\Policies;

use App\Domain\Auth\Permission;
use App\Domain\Booking\Booking;
use App\Models\User;

class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        return $booking->customer_id === $user->id
            || $user->hasPermissionTo(Permission::BookingsRead, $booking->organization);
    }

    /**
     * Confirm / check-in / complete are staff actions.
     */
    public function update(User $user, Booking $booking): bool
    {
        return $user->hasPermissionTo(Permission::BookingsUpdate, $booking->organization);
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $booking->customer_id === $user->id
            || $user->hasPermissionTo(Permission::BookingsCancel, $booking->organization);
    }

    public function reschedule(User $user, Booking $booking): bool
    {
        return $booking->customer_id === $user->id
            || $user->hasPermissionTo(Permission::BookingsUpdate, $booking->organization);
    }
}
