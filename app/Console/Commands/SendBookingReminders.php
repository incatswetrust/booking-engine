<?php

namespace App\Console\Commands;

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingReminderSent;
use App\Domain\Booking\BookingStatus;
use App\Notifications\BookingReminderNotification;
use Illuminate\Console\Command;

/**
 * §40: configurable offsets (organization.settings.reminder_offsets_minutes,
 * default 24h/2h/15min), run every minute (§62). booking_reminders_sent
 * is the dedup ledger -- without it, a booking sitting inside an
 * offset's window across multiple one-minute runs would get the same
 * reminder re-sent every minute until the window closes.
 */
class SendBookingReminders extends Command
{
    protected $signature = 'bookings:send-reminders';

    protected $description = 'Send booking reminders for offsets configured per organization (§40, §62)';

    public function handle(): int
    {
        $sent = 0;

        Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->where('start_at', '>', now())
            ->with(['organization', 'customer'])
            ->chunkById(100, function ($bookings) use (&$sent) {
                foreach ($bookings as $booking) {
                    $sent += $this->sendDueReminders($booking);
                }
            });

        $this->info("Sent {$sent} booking reminder(s).");

        return self::SUCCESS;
    }

    private function sendDueReminders(Booking $booking): int
    {
        $offsets = $booking->organization->settings['reminder_offsets_minutes'] ?? [1440, 120, 15];
        $sent = 0;

        foreach ($offsets as $offsetMinutes) {
            $offsetMinutes = (int) $offsetMinutes;

            // The offset's trigger time (start_at - offset) hasn't been
            // reached yet -- nothing to do this run.
            if ($booking->start_at->gt(now()->addMinutes($offsetMinutes))) {
                continue;
            }

            $alreadySent = BookingReminderSent::where('booking_id', $booking->id)
                ->where('offset_minutes', $offsetMinutes)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            $booking->customer->notify(new BookingReminderNotification([
                ...$booking->toOutboxPayload(),
                'offset_minutes' => $offsetMinutes,
            ]));

            BookingReminderSent::create([
                'booking_id' => $booking->id,
                'offset_minutes' => $offsetMinutes,
                'sent_at' => now(),
            ]);

            $sent++;
        }

        return $sent;
    }
}
