<?php

namespace App\Console\Commands;

use App\Domain\Booking\BookingHold;
use Illuminate\Console\Command;

class ExpireBookingHolds extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Delete booking holds past their expiry (§21, §62)';

    public function handle(): int
    {
        $deleted = BookingHold::where('expires_at', '<', now())->delete();

        $this->info("Deleted {$deleted} expired booking hold(s).");

        return self::SUCCESS;
    }
}
