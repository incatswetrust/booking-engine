<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\BookingHoldService;
use App\Domain\Booking\BookingHold;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingHold\StoreBookingHoldRequest;
use App\Http\Resources\BookingHoldResource;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Booking Holds')]
class BookingHoldController extends Controller
{
    public function __construct(private readonly BookingHoldService $holds) {}

    #[OA\Post(
        path: '/api/v1/booking-holds',
        summary: 'Temporarily hold a resource slot so other customers cannot book it (§21)',
        tags: ['Booking Holds'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'Slot held'),
            new OA\Response(response: 409, description: 'Slot no longer available'),
        ],
    )]
    public function store(StoreBookingHoldRequest $request): JsonResponse
    {
        $resource = Resource::where('public_id', $request->validated('resource_id'))->firstOrFail();
        $service = Service::where('public_id', $request->validated('service_id'))->firstOrFail();

        $hold = $this->holds->create(
            $request->user(),
            $resource,
            $service,
            CarbonImmutable::parse($request->validated('start_at')),
        );

        return (new BookingHoldResource($hold))->response()->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/api/v1/booking-holds/{bookingHold}',
        summary: 'Release a booking hold before it expires',
        tags: ['Booking Holds'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Hold released')],
    )]
    public function destroy(BookingHold $bookingHold): Response
    {
        if ($bookingHold->customer_id !== request()->user()->id) {
            throw new AuthorizationException('You can only release your own booking holds.');
        }

        $bookingHold->delete();

        return response()->noContent();
    }
}
