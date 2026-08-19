<?php

namespace App\Application\Services;

use App\Domain\Location\Location;
use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceAllocationStrategy;
use App\Domain\Resource\ResourceAllocationStrategyResolver;
use App\Domain\Service\Service;
use App\Http\Errors\ApiException;
use App\Http\Errors\ErrorCode;
use Carbon\CarbonInterface;

/**
 * §70: picks a resource for a booking that didn't name one -- narrows
 * the service's resources down to those eligible AND actually free for
 * this exact slot/party_size (AvailabilityService::isBookable(), a
 * snapshot read), then hands the survivors to whichever strategy the
 * organization configured to pick one. The chosen resource still goes
 * through BookingService::create()'s own lock + capacity check same as
 * a manually-specified one -- this only decides which resource looks
 * like a good candidate, it isn't itself the concurrency guard.
 */
class ResourceAllocationService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly ResourceAllocationStrategyResolver $strategies,
    ) {}

    public function allocate(
        Service $service,
        ?Location $location,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        int $partySize,
        ResourceAllocationStrategy $strategy,
    ): Resource {
        $candidates = $this->availability
            ->eligibleResourcesForAllocation($service, $location, $partySize)
            ->filter(fn (Resource $resource) => $this->availability->isBookable($resource, $startAt, $endAt, $partySize))
            ->values();

        if ($candidates->isEmpty()) {
            throw new ApiException(
                ErrorCode::BookingSlotUnavailable,
                'No resource is available for this service at the requested time.',
                409,
                ['start_at' => $startAt->toIso8601String()],
            );
        }

        return $this->strategies->resolve($strategy)->choose($candidates);
    }
}
