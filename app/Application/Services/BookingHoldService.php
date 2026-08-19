<?php

namespace App\Application\Services;

use App\Domain\Booking\BookingHold;
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
 * only one wins the application-level overlap check. PostgreSQL's
 * booking_holds_no_overlap exclusion constraint (see the booking_holds
 * migration) is the real backstop under true concurrency — the lock
 * reduces contention/wasted work, it isn't the source of truth.
 */
class BookingHoldService
{
    private const HOLD_MINUTES = 10;

    private const LOCK_WAIT_SECONDS = 5;

    public function create(User $customer, Resource $resource, Service $service, CarbonInterface $startAt): BookingHold
    {
        $endAt = $startAt->copy()->addMinutes($service->duration_minutes);

        $lock = Cache::lock("booking-hold:resource:{$resource->id}", self::LOCK_WAIT_SECONDS + 2);

        try {
            return $lock->block(self::LOCK_WAIT_SECONDS, function () use ($customer, $resource, $service, $startAt, $endAt) {
                $this->assertSlotIsFree($resource, $startAt, $endAt);

                try {
                    return BookingHold::create([
                        'resource_id' => $resource->id,
                        'service_id' => $service->id,
                        'customer_id' => $customer->id,
                        'start_at' => $startAt,
                        'end_at' => $endAt,
                        'expires_at' => now()->addMinutes(self::HOLD_MINUTES),
                    ]);
                } catch (QueryException $e) {
                    throw $this->isOverlapViolation($e) ? $this->slotUnavailable($resource, $startAt) : $e;
                }
            });
        } catch (LockTimeoutException) {
            throw $this->slotUnavailable($resource, $startAt);
        }
    }

    private function assertSlotIsFree(Resource $resource, CarbonInterface $startAt, CarbonInterface $endAt): void
    {
        $overlaps = BookingHold::query()
            ->where('resource_id', $resource->id)
            ->where('expires_at', '>', now())
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->exists();

        if ($overlaps) {
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
