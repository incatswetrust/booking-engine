<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\BookingService;
use App\Application\Services\PaymentService;
use App\Domain\Auth\Permission;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingHold;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Payment\Payment;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\RescheduleBookingRequest;
use App\Http\Requests\Booking\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\PaymentResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Bookings')]
class BookingController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly BookingService $bookings,
        private readonly PaymentService $payments,
    ) {}

    #[OA\Get(
        path: '/api/v1/bookings',
        summary: 'List bookings visible to the current user (own bookings + orgs they can read)',
        tags: ['Bookings'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Bookings list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Booking::query()->with(['organization', 'customer', 'service', 'resource', 'location']);

        if (! $user->is_platform_admin) {
            $readableOrgIds = $user->organizations
                ->filter(fn (Organization $org) => $user->hasPermissionTo(Permission::BookingsRead, $org))
                ->pluck('id');

            $query->where(function ($q) use ($user, $readableOrgIds) {
                $q->where('customer_id', $user->id);

                if ($readableOrgIds->isNotEmpty()) {
                    $q->orWhereIn('organization_id', $readableOrgIds);
                }
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('resource_id')) {
            $resource = Resource::where('public_id', $request->query('resource_id'))->first();
            $query->where('resource_id', $resource?->id ?? 0);
        }

        if ($request->filled('service_id')) {
            $service = Service::where('public_id', $request->query('service_id'))->first();
            $query->where('service_id', $service?->id ?? 0);
        }

        if ($request->filled('customer_id')) {
            $customer = User::where('public_id', $request->query('customer_id'))->first();
            $query->where('customer_id', $customer?->id ?? 0);
        }

        if ($request->filled('location_id')) {
            $location = Location::where('public_id', $request->query('location_id'))->first();
            $query->where('location_id', $location?->id ?? 0);
        }

        if ($request->filled('date_from')) {
            $query->where('start_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('start_at', '<=', $request->query('date_to'));
        }

        $sort = (string) $request->query('sort', '-start_at');
        $column = ltrim($sort, '-');
        $column = in_array($column, ['start_at', 'created_at'], true) ? $column : 'start_at';
        $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc');

        return BookingResource::collection($query->cursorPaginate(20)->withQueryString());
    }

    #[OA\Post(
        path: '/api/v1/bookings',
        summary: 'Create a booking (§25)',
        tags: ['Bookings'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'Booking created'),
            new OA\Response(response: 409, description: 'Slot no longer available'),
        ],
    )]
    public function store(StoreBookingRequest $request): JsonResponse
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

        $hold = $request->filled('hold_id')
            ? BookingHold::where('public_id', $request->validated('hold_id'))->first()
            : null;

        $booking = $this->bookings->create(
            $actor,
            $customer,
            $resource,
            $service,
            CarbonImmutable::parse($request->validated('start_at')),
            (int) $request->validated('party_size', 1),
            $request->validated('notes'),
            $hold,
        );

        return (new BookingResource($booking))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/bookings/{booking}',
        summary: 'Get a booking by its public id',
        tags: ['Bookings'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Booking')],
    )]
    public function show(Booking $booking): BookingResource
    {
        $this->authorize('view', $booking);

        return new BookingResource($booking);
    }

    #[OA\Post(
        path: '/api/v1/bookings/{booking}/confirm',
        summary: 'Confirm a pending booking',
        tags: ['Bookings'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Booking confirmed')],
    )]
    public function confirm(Request $request, Booking $booking): BookingResource
    {
        $this->authorize('update', $booking);

        return new BookingResource($this->bookings->confirm($request->user(), $booking));
    }

    #[OA\Post(
        path: '/api/v1/bookings/{booking}/check-in',
        summary: 'Check a customer in for their booking',
        tags: ['Bookings'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Booking checked in')],
    )]
    public function checkIn(Request $request, Booking $booking): BookingResource
    {
        $this->authorize('update', $booking);

        return new BookingResource($this->bookings->checkIn($request->user(), $booking));
    }

    #[OA\Post(
        path: '/api/v1/bookings/{booking}/complete',
        summary: 'Mark a booking as completed',
        tags: ['Bookings'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Booking completed')],
    )]
    public function complete(Request $request, Booking $booking): BookingResource
    {
        $this->authorize('update', $booking);

        return new BookingResource($this->bookings->complete($request->user(), $booking));
    }

    #[OA\Post(
        path: '/api/v1/bookings/{booking}/cancel',
        summary: 'Cancel a booking, evaluating the cancellation policy (§28)',
        tags: ['Bookings'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Booking cancelled')],
    )]
    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('cancel', $booking);

        $withinFreeWindow = $this->bookings->isWithinFreeCancellationWindow($booking);
        $booking = $this->bookings->cancel($request->user(), $booking);

        return (new BookingResource($booking))
            ->additional(['meta' => ['free_cancellation' => $withinFreeWindow]])
            ->response();
    }

    #[OA\Post(
        path: '/api/v1/bookings/{booking}/reschedule',
        summary: 'Atomically move a booking to a new start time (§27)',
        tags: ['Bookings'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Booking rescheduled'),
            new OA\Response(response: 409, description: 'New slot not available'),
        ],
    )]
    public function reschedule(RescheduleBookingRequest $request, Booking $booking): BookingResource
    {
        $booking = $this->bookings->reschedule(
            $request->user(),
            $booking,
            CarbonImmutable::parse($request->validated('start_at')),
        );

        return new BookingResource($booking);
    }

    #[OA\Post(
        path: '/api/v1/bookings/{booking}/payment',
        summary: 'Start a Stripe PaymentIntent for a booking that requires payment (§30, §31)',
        tags: ['Bookings'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'Payment created, includes the Stripe client_secret to complete it'),
            new OA\Response(response: 422, description: 'Booking does not need payment right now, or already has an active one'),
        ],
    )]
    public function payment(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('initiate', [Payment::class, $booking]);

        $result = $this->payments->createForBooking($booking);

        $payload = array_merge(
            (new PaymentResource($result['payment']))->toArray($request),
            ['client_secret' => $result['client_secret']],
        );

        return response()->json(['data' => $payload], 201);
    }
}
