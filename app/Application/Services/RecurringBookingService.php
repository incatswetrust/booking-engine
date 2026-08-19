<?php

namespace App\Application\Services;

use App\Domain\Booking\Booking;
use App\Domain\Booking\RecurringBookingStrategy;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use App\Http\Errors\ApiException;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * §72: creates a series of weekly-recurring bookings (e.g. "every
 * Tuesday 18:00, 8 weeks") -- each occurrence goes through the exact
 * same BookingService::create() a single booking would, so capacity
 * (§24), pricing (§71), calendar sync (§36) and webhook dispatch (§41)
 * all fire per-occurrence exactly as they normally would. This class
 * only decides HOW MANY of those create() calls to make and what to do
 * when one of them conflicts.
 */
class RecurringBookingService
{
    public function __construct(private readonly BookingService $bookings) {}

    /**
     * @return array{recurring_booking_id: string, bookings: array<int, Booking>, skipped: array<int, array{start_at: string, reason: string}>}
     */
    public function create(
        User $actor,
        User $customer,
        Resource $resource,
        Service $service,
        CarbonInterface $firstStartAt,
        int $occurrences,
        int $partySize,
        ?string $notes,
        RecurringBookingStrategy $strategy,
    ): array {
        $recurringBookingId = (string) Str::ulid();
        $occurrenceStarts = $this->occurrenceStarts($firstStartAt, $occurrences);

        return $strategy === RecurringBookingStrategy::AllOrNothing
            ? $this->createAllOrNothing($actor, $customer, $resource, $service, $occurrenceStarts, $partySize, $notes, $recurringBookingId)
            : $this->createBookAvailable($actor, $customer, $resource, $service, $occurrenceStarts, $partySize, $notes, $recurringBookingId);
    }

    /**
     * @return array<int, CarbonInterface>
     */
    private function occurrenceStarts(CarbonInterface $firstStartAt, int $occurrences): array
    {
        return array_map(
            fn (int $i) => $firstStartAt->copy()->addWeeks($i),
            range(0, $occurrences - 1),
        );
    }

    /**
     * A single outer transaction around every occurrence's own create()
     * call: if any occurrence throws (its own lock + capacity check
     * failed), the exception propagates out of the closure and Laravel
     * rolls the whole transaction back -- including every occurrence
     * already committed via create()'s own inner transaction, since
     * nested DB::transaction() calls use SAVEPOINTs. The failing
     * occurrence's own ApiException (complete with §73 alternatives)
     * propagates to the caller unchanged, so the client sees exactly
     * which occurrence conflicted.
     *
     * Note: create()'s Metrics::bookingCreated() calls and availability
     * cache invalidation happen as plain PHP side effects after its own
     * inner transaction, not inside it -- so a rolled-back occurrence
     * can still have bumped the booking-created counter and busted the
     * cache once. Harmless (metrics/cache aren't correctness-critical
     * here), so not worth restructuring create() to avoid it.
     *
     * @param  array<int, CarbonInterface>  $occurrenceStarts
     * @return array{recurring_booking_id: string, bookings: array<int, Booking>, skipped: array<int, array{start_at: string, reason: string}>}
     */
    private function createAllOrNothing(
        User $actor,
        User $customer,
        Resource $resource,
        Service $service,
        array $occurrenceStarts,
        int $partySize,
        ?string $notes,
        string $recurringBookingId,
    ): array {
        $bookings = DB::transaction(function () use ($actor, $customer, $resource, $service, $occurrenceStarts, $partySize, $notes, $recurringBookingId) {
            return array_map(
                fn (CarbonInterface $startAt) => $this->createOccurrence($actor, $customer, $resource, $service, $startAt, $partySize, $notes, $recurringBookingId),
                $occurrenceStarts,
            );
        });

        return ['recurring_booking_id' => $recurringBookingId, 'bookings' => $bookings, 'skipped' => []];
    }

    /**
     * @param  array<int, CarbonInterface>  $occurrenceStarts
     * @return array{recurring_booking_id: string, bookings: array<int, Booking>, skipped: array<int, array{start_at: string, reason: string}>}
     */
    private function createBookAvailable(
        User $actor,
        User $customer,
        Resource $resource,
        Service $service,
        array $occurrenceStarts,
        int $partySize,
        ?string $notes,
        string $recurringBookingId,
    ): array {
        $bookings = [];
        $skipped = [];

        foreach ($occurrenceStarts as $startAt) {
            try {
                $bookings[] = $this->createOccurrence($actor, $customer, $resource, $service, $startAt, $partySize, $notes, $recurringBookingId);
            } catch (ApiException $e) {
                $skipped[] = ['start_at' => $startAt->toIso8601String(), 'reason' => $e->getMessage()];
            }
        }

        return ['recurring_booking_id' => $recurringBookingId, 'bookings' => $bookings, 'skipped' => $skipped];
    }

    private function createOccurrence(
        User $actor,
        User $customer,
        Resource $resource,
        Service $service,
        CarbonInterface $startAt,
        int $partySize,
        ?string $notes,
        string $recurringBookingId,
    ): Booking {
        $booking = $this->bookings->create($actor, $customer, $resource, $service, $startAt, $partySize, $notes);
        $booking->forceFill(['recurring_booking_id' => $recurringBookingId])->save();

        return $booking;
    }
}
