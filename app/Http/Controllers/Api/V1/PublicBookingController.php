<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\BookingService;
use App\Application\Services\ResourceAllocationService;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceAllocationStrategy;
use App\Domain\Service\Service;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StorePublicBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

/**
 * §69 "public booking pages": the unauthenticated counterpart to
 * POST /bookings, for an external booking widget/page a client builds
 * against this API directly (no login step). A visitor is identified by
 * email alone -- find-or-create a User for it, exactly like a real
 * registered customer from then on (same Booking rows, same policies,
 * same notifications), just without ever issuing them a session/token.
 * Reuses BookingService::create() end to end, so capacity/pricing/
 * calendar-sync/webhooks all behave identically to an authenticated
 * booking.
 */
#[OA\Tag(name: 'Public Booking')]
class PublicBookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly ResourceAllocationService $allocation,
    ) {}

    #[OA\Post(
        path: '/api/v1/public/bookings',
        summary: 'Create a booking without authentication, for a public booking page/widget (§69)',
        tags: ['Public Booking'],
        responses: [
            new OA\Response(response: 201, description: 'Booking created'),
            new OA\Response(response: 409, description: 'Slot no longer available'),
            new OA\Response(response: 429, description: 'Rate limited (stricter than authenticated endpoints, IP-only)'),
        ],
    )]
    public function store(StorePublicBookingRequest $request): JsonResponse
    {
        $service = Service::where('public_id', $request->validated('service_id'))->firstOrFail();
        $organization = Organization::findOrFail($service->organization_id);

        $customer = $this->findOrCreateGuest($request->validated('customer_name'), $request->validated('customer_email'));
        $partySize = (int) $request->validated('party_size', 1);
        $startAt = CarbonImmutable::parse($request->validated('start_at'));

        if ($request->filled('resource_id')) {
            $resource = Resource::where('public_id', $request->validated('resource_id'))->firstOrFail();
        } else {
            $location = $request->filled('location_id')
                ? Location::where('public_id', $request->validated('location_id'))->first()
                : null;

            $strategy = ResourceAllocationStrategy::from($organization->settings['resource_allocation_strategy'] ?? 'first_available');

            $resource = $this->allocation->allocate(
                $service,
                $location,
                $startAt,
                $startAt->copy()->addMinutes($service->duration_minutes),
                $partySize,
                $strategy,
            );
        }

        // A public visitor always books as themselves -- $actor and
        // $customer are the same user, same as an authenticated
        // customer self-booking via POST /bookings.
        $booking = $this->bookings->create(
            $customer,
            $customer,
            $resource,
            $service,
            $startAt,
            $partySize,
            $request->validated('notes'),
        );

        return (new BookingResource($booking))->response()->setStatusCode(201);
    }

    private function findOrCreateGuest(string $name, string $email): User
    {
        $user = User::where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        return User::create([
            'name' => $name,
            'email' => $email,
            // Unguessable and never handed to the visitor -- this
            // identity is only usable through the public booking flow
            // (by email) unless they later go through a real password
            // reset, same as any account they didn't set a password for.
            // The User model's `password` cast hashes this on write,
            // same as AuthController::register().
            'password' => Str::random(40),
        ]);
    }
}
