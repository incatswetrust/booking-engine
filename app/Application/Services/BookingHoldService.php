<?php

namespace App\Application\Services;

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingHold;
use App\Domain\Booking\BookingStatus;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use App\Http\Errors\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Creates booking holds under a per-resource Redis lock (§21, §22).
 *
 * The lock serializes concurrent hold attempts for the same resource so
 * only one wins the application-level capacity check. For a capacity = 1
 * resource, PostgreSQL's booking_holds_no_overlap exclusion constraint
 * (see the capacity-support migration) is still the real backstop under
 * true concurrency; for capacity > 1 it can't express a numeric
 * threshold, so this lock + the SUM(party_size) check below is the sole
 * guard (§24) — see the migration's docblock for the full reasoning.
 */
class BookingHoldService
{
    private const HOLD_MINUTES = 10;

    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly AvailabilityCache $availabilityCache,
        private readonly AvailabilityService $availability,
    ) {}

    public function create(User $customer, Resource $resource, Service $service, CarbonInterface $startAt, int $partySize = 1): BookingHold
    {
        $endAt = $startAt->copy()->addMinutes($service->duration_minutes);

        // Shares its lock namespace with BookingService: hold creation and
        // booking creation must serialize against each other too, or a
        // booking could be created over a slot someone else is holding.
        $lock = Cache::lock("booking:resource:{$resource->id}", self::LOCK_WAIT_SECONDS + 2);

        try {
            $hold = $lock->block(self::LOCK_WAIT_SECONDS, function () use ($customer, $resource, $service, $startAt, $endAt, $partySize) {
                $this->assertWithinWorkingHours($resource, $startAt, $endAt);
                $this->assertCapacityAvailable($resource, $startAt, $endAt, $partySize);

                try {
                    return BookingHold::create([
                        'resource_id' => $resource->id,
                        'service_id' => $service->id,
                        'customer_id' => $customer->id,
                        'start_at' => $startAt,
                        'end_at' => $endAt,
                        'expires_at' => now()->addMinutes(self::HOLD_MINUTES),
                        'party_size' => $partySize,
                        'resource_capacity' => $resource->capacity,
                    ]);
                } catch (QueryException $e) {
                    throw $this->isOverlapViolation($e) ? $this->slotUnavailable($resource, $startAt) : $e;
                }
            });
        } catch (LockTimeoutException) {
            throw $this->slotUnavailable($resource, $startAt);
        }

        $this->availabilityCache->forgetForResource($resource);

        return $hold;
    }

    private function assertWithinWorkingHours(Resource $resource, CarbonInterface $startAt, CarbonInterface $endAt): void
    {
        if (! $this->availability->isWithinWorkingHours($resource, $startAt, $endAt)) {
            throw $this->slotUnavailable($resource, $startAt);
        }
    }

    /**
     * §24: mirrors BookingService::assertCapacityAvailable() — a new hold
     * must not push the sum of party_size across every overlapping,
     * still-active booking and non-expired hold past the resource's
     * capacity. Deliberately duplicated rather than shared: holds and
     * bookings are different tables/lifecycles, same reasoning as the
     * rest of this class's relationship to BookingService.
     */
    private function assertCapacityAvailable(Resource $resource, CarbonInterface $startAt, CarbonInterface $endAt, int $partySize): void
    {
        $bookedCapacity = (int) Booking::query()
            ->where('resource_id', $resource->id)
            ->whereIn('status', array_map(fn (BookingStatus $s) => $s->value, BookingStatus::active()))
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->sum('party_size');

        $heldCapacity = (int) BookingHold::query()
            ->where('resource_id', $resource->id)
            ->where('expires_at', '>', now())
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->sum('party_size');

        if ($bookedCapacity + $heldCapacity + $partySize > $resource->capacity) {
            throw $this->slotUnavailable($resource, $startAt);
        }
    }

    private function isOverlapViolation(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'booking_holds_no_overlap')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    private function slotUnavailable(Resource $resource, CarbonInterface $startAt): ApiException
    {
        return new ApiException(
            ErrorCode::BookingSlotUnavailable,
            'The selected booking slot is no longer available.',
            409,
            [
                'resource_id' => $resource->public_id,
                'start_at' => $startAt->toIso8601String(),
            ],
        );
    }
}
