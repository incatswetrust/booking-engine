<?php

namespace App\Console\Commands;

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStateMachine;
use App\Domain\Booking\BookingStatus;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStateMachine;
use App\Domain\Payment\PaymentStatus;
use Illuminate\Console\Command;

/**
 * §62/§64's "payment timeout" scenario: a booking that blocked
 * confirmation on payment (full/deposit, §31) but never got paid
 * shouldn't hold the slot forever. Threshold is per-organization
 * (settings.payment_timeout_minutes, default 30) rather than a single
 * global cutoff, since organizations set their own booking policies
 * throughout this app.
 *
 * This was a documented gap through Phase 1 -- ExpirePendingBooking
 * wasn't needed while bookings always confirmed immediately with no
 * Stripe integration. Now that awaiting_payment is a real, possibly
 * long-lived state (§30), it is.
 */
class ExpirePendingBookings extends Command
{
    protected $signature = 'bookings:expire-pending';

    protected $description = 'Expire bookings stuck in awaiting_payment past their organization\'s payment timeout (§62, §64)';

    public function __construct(
        private readonly BookingStateMachine $bookingStateMachine,
        private readonly PaymentStateMachine $paymentStateMachine,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $expired = 0;

        Booking::query()
            ->where('status', BookingStatus::AwaitingPayment)
            ->with('organization')
            ->chunkById(100, function ($bookings) use (&$expired) {
                foreach ($bookings as $booking) {
                    $timeoutMinutes = (int) ($booking->organization->settings['payment_timeout_minutes'] ?? 30);

                    if ($booking->created_at->copy()->addMinutes($timeoutMinutes)->isFuture()) {
                        continue;
                    }

                    $this->bookingStateMachine->transition($booking, BookingStatus::Expired);

                    Payment::where('booking_id', $booking->id)
                        ->where('status', PaymentStatus::Pending)
                        ->get()
                        ->each(fn (Payment $payment) => $this->paymentStateMachine->transition(
                            $payment,
                            PaymentStatus::Failed,
                            ['failure_reason' => 'Booking expired before payment completed.'],
                        ));

                    $expired++;
                }
            });

        $this->info("Expired {$expired} booking(s) stuck in awaiting_payment.");

        return self::SUCCESS;
    }
}
