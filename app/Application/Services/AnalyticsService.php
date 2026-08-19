<?php

namespace App\Application\Services;

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * §5/§69: backs the Organization Owner's "видеть статистику" (`GET
 * /organizations/{organization}/statistics`) -- the spec gives no
 * further detail than that bullet, so the shape here is a reasonable,
 * self-contained interpretation: booking volume/status breakdown,
 * revenue (booked, not net-of-refund -- see revenueByCurrency()),
 * cancellation rate, and the busiest services/resources for the period.
 */
class AnalyticsService
{
    private const TOP_N = 5;

    /**
     * Bookings that actually happened or are still on track to --
     * excludes pending/held/awaiting_payment (not yet real revenue) and
     * cancelled/no_show/expired (never will be). This is "booked"
     * revenue at each booking's own §71 price, not net of any later
     * refund (§28) -- a simpler, consistent figure than reconciling
     * against payments, which not every booking even has one of
     * (payment_mode "none").
     */
    private const REVENUE_STATUSES = [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed];

    /**
     * @return array<string, mixed>
     */
    public function forOrganization(Organization $organization, CarbonInterface $dateFrom, CarbonInterface $dateTo): array
    {
        $bookings = Booking::where('bookings.organization_id', $organization->id)
            ->where('bookings.start_at', '>=', $dateFrom)
            ->where('bookings.start_at', '<=', $dateTo);

        $byStatus = (clone $bookings)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $total = (int) $byStatus->sum();
        $cancelled = (int) ($byStatus[BookingStatus::Cancelled->value] ?? 0);

        return [
            'period' => ['from' => $dateFrom->toIso8601String(), 'to' => $dateTo->toIso8601String()],
            'bookings' => [
                'total' => $total,
                'by_status' => $byStatus->map(fn ($count) => (int) $count)->all(),
            ],
            'cancellation_rate' => $total > 0 ? round($cancelled / $total * 100, 2) : 0.0,
            'revenue' => $this->revenueByCurrency($bookings),
            'top_services' => $this->topBy($bookings, 'services', 'service_id'),
            'top_resources' => $this->topBy($bookings, 'resources', 'resource_id'),
        ];
    }

    /**
     * @param  Builder<Booking>  $bookings
     * @return array<int, array{currency: string, amount: float}>
     */
    private function revenueByCurrency($bookings): array
    {
        return (clone $bookings)
            ->whereIn('status', array_map(fn (BookingStatus $s) => $s->value, self::REVENUE_STATUSES))
            ->selectRaw('currency, sum(price) as total')
            ->groupBy('currency')
            ->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'amount' => (float) $row->total])
            ->all();
    }

    /**
     * @param  Builder<Booking>  $bookings
     * @param  'services'|'resources'  $table
     * @return array<int, array{id: string, name: string, bookings: int}>
     */
    private function topBy($bookings, string $table, string $foreignKey): array
    {
        return (clone $bookings)
            ->join($table, "{$table}.id", '=', "bookings.{$foreignKey}")
            ->selectRaw("{$table}.public_id as public_id, {$table}.name as name, count(*) as bookings_count")
            ->groupBy("{$table}.id", "{$table}.public_id", "{$table}.name")
            ->orderByDesc('bookings_count')
            ->limit(self::TOP_N)
            ->get()
            ->map(fn ($row) => ['id' => $row->public_id, 'name' => $row->name, 'bookings' => (int) $row->bookings_count])
            ->all();
    }
}
