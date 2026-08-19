<?php

namespace App\Policies;

use App\Domain\Auth\Permission;
use App\Domain\Booking\Booking;
use App\Domain\Payment\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function view(User $user, Payment $payment): bool
    {
        return $payment->booking->customer_id === $user->id
            || $user->hasPermissionTo(Permission::PaymentsRead, $payment->booking->organization);
    }

    /**
     * Starting a Stripe PaymentIntent for a booking (POST
     * /bookings/{id}/payment) -- the customer paying for their own
     * booking, or staff collecting payment on their behalf (e.g. a
     * pay_after booking, or a phone/in-person payment).
     */
    public function initiate(User $user, Booking $booking): bool
    {
        return $booking->customer_id === $user->id
            || $user->hasPermissionTo(Permission::PaymentsManage, $booking->organization);
    }

    /**
     * Refunds are staff-only -- a customer can't self-refund.
     */
    public function refund(User $user, Payment $payment): bool
    {
        return $user->hasPermissionTo(Permission::PaymentsManage, $payment->booking->organization);
    }
}
