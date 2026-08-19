<?php

namespace App\Application\Services;

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingHold;
use App\Domain\Booking\BookingStateMachine;
use App\Domain\Booking\BookingStatus;
use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceBlock;
use App\Domain\Service\Service;
use App\Http\Errors\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Booking creation and rescheduling follow the same lock-then-recheck
 * pattern as BookingHoldService (§21-§23) — this is deliberate duplication
 * rather than a shared abstraction, since holds and bookings have
 * different tables/constraints and the two call sites are small.
 */
class BookingService
{
    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly BookingStateMachine $stateMachine,
        private readonly AvailabilityCache $availabilityCache,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function create(
        User $actor,
        User $customer,
        Resource $resource,
        Service $service,
        CarbonInterface $startAt,
        int $partySize,
        ?string $notes,
        ?BookingHold $consumedHold = null,
    ): Booking {
        $endAt = $startAt->copy()->addMinutes($service->duration_minutes);

        return $this->withResourceLock($resource, $startAt, function () use (
            $actor, $customer, $resource, $service, $startAt, $endAt, $partySize, $notes, $consumedHold,
        ) {
            $this->assertSlotIsFree($resource, $startAt, $endAt);
            $this->assertNoBlockingBlock($resource, $startAt, $endAt);
            $this->assertNoConflictingHold($resource, $startAt, $endAt, $consumedHold);

            try {
                $booking = DB::transaction(function () use (
                    $actor, $customer, $resource, $service, $startAt, $endAt, $partySize, $notes, $consumedHold,
                ) {
                    $booking = Booking::create([
                        'organization_id' => $resource->organization_id,
                        'customer_id' => $customer->id,
                        'service_id' => $service->id,
                        'resource_id' => $resource->id,
                        'location_id' => $resource->location_id,
                        'start_at' => $startAt,
                        'end_at' => $endAt,
                        'status' => BookingStatus::Pending,
                        'price' => $service->price,
                        'currency' => $service->currency,
                        'notes' => $notes,
                        'party_size' => $partySize,
                    ]);

                    $booking->statusHistory()->create([
                        'from_status' => null,
                        'to_status' => BookingStatus::Pending->value,
                        'changed_by_user_id' => $actor->id,
                    ]);

                    $this->auditLogger->log('booking.created', $booking, null, $booking->toArray());

                    // MVP ships without Stripe (§66): every booking is
                    // effectively "no payment required" for now, so it
                    // confirms immediately instead of parking in
                    // awaiting_payment. Phase 2 payments will insert a real
                    // awaiting_payment step ahead of this transition.
                    $this->stateMachine->transition($booking, BookingStatus::Confirmed, $actor);

                    $consumedHold?->delete();

                    return $booking;
                });
            } catch (QueryException $e) {
                throw $this->isOverlapViolation($e) ? $this->slotUnavailable($resource, $startAt) : $e;
            }

            $this->availabilityCache->forgetForResource($resource);

            return $booking;
        });
    }

    public function reschedule(User $actor, Booking $booking, CarbonInterface $newStartAt): Booking
    {
        if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Held, BookingStatus::AwaitingPayment, BookingStatus::Confirmed], true)) {
            throw new ApiException(
                ErrorCode::BookingCannotBeRescheduled,
                "A booking with status \"{$booking->status->value}\" cannot be rescheduled.",
                422,
            );
        }

        $resource = $booking->resource;
        $newEndAt = $newStartAt->copy()->addMinutes($booking->service->duration_minutes);

        return $this->withResourceLock($resource, $newStartAt, function () use ($booking, $resource, $newStartAt, $newEndAt) {
            $this->assertSlotIsFree($resource, $newStartAt, $newEndAt, excludeBookingId: $booking->id);
            $this->assertNoBlockingBlock($resource, $newStartAt, $newEndAt);

            $oldStartAt = $booking->start_at;
            $oldEndAt = $booking->end_at;

            try {
                // A single UPDATE on the existing row is what makes this
                // atomic (§27): the exclusion constraint is checked against
                // every other row in the same statement, so the old slot is
                // never released before the new one is confirmed free.
                $booking->forceFill(['start_at' => $newStartAt, 'end_at' => $newEndAt])->save();
            } catch (QueryException $e) {
                throw $this->isOverlapViolation($e) ? $this->slotUnavailable($resource, $newStartAt) : $e;
            }

            $this->auditLogger->log(
                'booking.rescheduled',
                $booking,
                ['start_at' => $oldStartAt->toIso8601String(), 'end_at' => $oldEndAt->toIso8601String()],
                ['start_at' => $newStartAt->toIso8601String(), 'end_at' => $newEndAt->toIso8601String()],
            );

            $this->availabilityCache->forgetForResource($resource);

            return $booking;
        });
    }

    public function cancel(User $actor, Booking $booking): Booking
    {
        if ($booking->status === BookingStatus::Cancelled) {
            throw new ApiException(ErrorCode::BookingAlreadyCancelled, 'This booking is already cancelled.', 409);
        }

        $booking = $this->stateMachine->transition($booking, BookingStatus::Cancelled, $actor);

        $this->availabilityCache->forgetForResource($booking->resource);

        return $booking;
    }

    /**
     * Free cancellation if now() is far enough ahead of the booking's
     * start per the organization's cancellation_notice_minutes setting
     * (§28). No payments exist yet to actually apply a late-cancellation
     * charge, so this only reports whether the window was missed —
     * Phase 2 payments will act on it.
     */
    public function isWithinFreeCancellationWindow(Booking $booking): bool
    {
        $noticeMinutes = (int) ($booking->organization->settings['cancellation_notice_minutes'] ?? 0);

        return now()->diffInMinutes($booking->start_at, false) >= $noticeMinutes;
    }

    public function confirm(User $actor, Booking $booking): Booking
    {
        return $this->stateMachine->transition($booking, BookingStatus::Confirmed, $actor);
    }

    public function checkIn(User $actor, Booking $booking): Booking
    {
        return $this->stateMachine->transition($booking, BookingStatus::CheckedIn, $actor);
    }

    public function complete(User $actor, Booking $booking): Booking
    {
        return $this->stateMachine->transition($booking, BookingStatus::Completed, $actor);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withResourceLock(Resource $resource, CarbonInterface $startAt, callable $callback): mixed
    {
        $lock = Cache::lock("booking:resource:{$resource->id}", self::LOCK_WAIT_SECONDS + 2);

        try {
            return $lock->block(self::LOCK_WAIT_SECONDS, $callback);
        } catch (LockTimeoutException) {
            throw $this->slotUnavailable($resource, $startAt);
        }
    }

    private function assertSlotIsFree(Resource $resource, CarbonInterface $startAt, CarbonInterface $endAt, ?int $excludeBookingId = null): void
    {
        $overlaps = Booking::query()
            ->where('resource_id', $resource->id)
            ->whereIn('status', array_map(fn (BookingStatus $s) => $s->value, BookingStatus::active()))
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->exists();

        if ($overlaps) {
            throw $this->slotUnavailable($resource, $startAt);
        }
    }

    /**
     * A booking must not be created over a slot someone else is actively
     * holding (§21) — holds and bookings live in separate tables with
     * separate exclusion constraints, so this is the only thing that
     * connects them. $consumedHold is excluded since that's the hold this
     * very booking is converting.
     */
    private function assertNoConflictingHold(Resource $resource, CarbonInterface $startAt, CarbonInterface $endAt, ?BookingHold $consumedHold): void
    {
        $conflicts = BookingHold::query()
            ->where('resource_id', $resource->id)
            ->where('expires_at', '>', now())
            ->when($consumedHold, fn ($q) => $q->where('id', '!=', $consumedHold->id))
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->exists();

        if ($conflicts) {
            throw $this->slotUnavailable($resource, $startAt);
        }
    }

    /**
     * Resource blocks (§16) aren't part of the exclusion constraint — they
     * live in a separate table — so this is an application-level check
     * only. A block created after a booking already exists doesn't retroactively
     * invalidate it.
     */
    private function assertNoBlockingBlock(Resource $resource, CarbonInterface $startAt, CarbonInterface $endAt): void
    {
        $blocked = ResourceBlock::query()
            ->where('resource_id', $resource->id)
            ->where('starts_at', '<', $endAt)
            ->where('ends_at', '>', $startAt)
            ->exists();

        if ($blocked) {
            throw $this->slotUnavailable($resource, $startAt);
        }
    }

    private function isOverlapViolation(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'bookings_no_overlap')
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
