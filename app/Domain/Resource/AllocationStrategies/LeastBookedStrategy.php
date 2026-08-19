<?php

namespace App\Domain\Resource\AllocationStrategies;

use App\Domain\Booking\BookingStatus;
use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceAllocationStrategyInterface;
use Illuminate\Support\Collection;

/**
 * Load-balances across candidates by picking whichever currently has the
 * fewest still-active bookings overall (not just in this slot) -- a
 * simple proxy for "who's least busy" without needing a rolling window
 * or historical stats.
 */
class LeastBookedStrategy implements ResourceAllocationStrategyInterface
{
    public function choose(Collection $candidates): Resource
    {
        return $candidates
            ->sortBy(fn (Resource $resource) => $resource->bookings()
                ->whereIn('status', array_map(fn (BookingStatus $s) => $s->value, BookingStatus::active()))
                ->count())
            ->first();
    }
}
