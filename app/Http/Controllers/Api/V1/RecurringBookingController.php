<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\RecurringBookingService;
use App\Domain\Auth\Permission;
use App\Domain\Booking\RecurringBookingStrategy;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StoreRecurringBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * §72: creates several weekly-recurring occurrences of the same booking
 * in one request. Deliberately requires an explicit resource_id -- §70's
 * auto-allocation could pick a DIFFERENT resource per occurrence, which
 * would defeat the point of a recurring series (the same person/room
 * every week), so that's out of scope here.
 */
#[OA\Tag(name: 'Recurring Bookings')]
class RecurringBookingController extends Controller
{
    public function __construct(private readonly RecurringBookingService $recurringBookings) {}

    #[OA\Post(
        path: '/api/v1/recurring-bookings',
        summary: 'Create a weekly-recurring series of bookings, e.g. "every Tuesday 18:00, 8 weeks" (§72)',
        tags: ['Recurring Bookings'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'Series created (all_or_nothing) or attempted (book_available, see "skipped")'),
            new OA\Response(response: 409, description: 'all_or_nothing: at least one occurrence conflicted, nothing was created'),
        ],
    )]
    public function store(StoreRecurringBookingRequest $request): JsonResponse
    {
        $actor = $request->user();
        $resource = Resource::where('public_id', $request->validated('resource_id'))->firstOrFail();
        $service = Service::where('public_id', $request->validated('service_id'))->firstOrFail();

        $customer = $actor;

        if ($request->filled('customer_id')) {
            $organization = Organization::findOrFail($resource->organization_id);

            if (! $actor->hasPermissionTo(Permission::BookingsCreate, $organization)) {
                throw new AuthorizationException('You are not allowed to create a booking on behalf of another customer.');
            }

            $customer = User::where('public_id', $request->validated('customer_id'))->firstOrFail();
        }

        $strategy = RecurringBookingStrategy::from($request->validated('strategy'));

        $result = $this->recurringBookings->create(
            $actor,
            $customer,
            $resource,
            $service,
            CarbonImmutable::parse($request->validated('first_start_at')),
            (int) $request->validated('occurrences'),
            (int) $request->validated('party_size', 1),
            $request->validated('notes'),
            $strategy,
        );

        return response()->json([
            'data' => [
                'recurring_booking_id' => $result['recurring_booking_id'],
                'strategy' => $strategy->value,
                'bookings' => BookingResource::collection(collect($result['bookings'])),
                'skipped' => $result['skipped'],
            ],
        ], 201);
    }
}
