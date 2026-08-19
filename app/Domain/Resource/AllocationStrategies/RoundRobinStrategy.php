<?php

namespace App\Domain\Resource\AllocationStrategies;

use App\Domain\Booking\BookingStatus;
use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceAllocationStrategyInterface;
use Illuminate\Support\Collection;

/**
 * Rotates through candidates by picking whichever was booked longest ago
 * (or never) -- a stateless approximation of round-robin that doesn't
 * need to persist "whose turn is next" anywhere, and self-corrects if a
 * resource is added/removed from the pool between requests.
 */
class RoundRobinStrategy implements ResourceAllocationStrategyInterface
{
    public function choose(Collection $candidates): Resource
    {
        return $candidates
            ->sortBy(fn (Resource $resource) => $resource->bookings()
                ->whereIn('status', array_map(fn (BookingStatus $s) => $s->value, BookingStatus::active()))
                ->max('created_at') ?? '')
            ->first();
    }
}
