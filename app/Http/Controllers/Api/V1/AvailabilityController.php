<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\AvailabilityService;
use App\Domain\Location\Location;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use App\Http\Controllers\Controller;
use App\Http\Requests\Availability\AvailabilityRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Availability')]
class AvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityService $availability) {}

    #[OA\Get(
        path: '/api/v1/availability',
        summary: 'Compute bookable slots for a service, across one resource or all resources offering it (§17)',
        tags: ['Availability'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'service_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'resource_id', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'timezone', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'party_size', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Available slots grouped by date')],
    )]
    public function index(AvailabilityRequest $request): JsonResponse
    {
        $service = Service::where('public_id', $request->validated('service_id'))->firstOrFail();

        $resource = $request->filled('resource_id')
            ? Resource::where('public_id', $request->validated('resource_id'))->firstOrFail()
            : null;

        $location = $request->filled('location_id')
            ? Location::where('public_id', $request->validated('location_id'))->firstOrFail()
            : null;

        $timezone = $request->validated('timezone')
            ?? $resource?->location?->timezone
            ?? $location?->timezone
            ?? $service->organization->timezone;

        $data = $this->availability->forService(
            $service,
            $location,
            $resource,
            CarbonImmutable::parse($request->validated('date_from'), $timezone)->startOfDay(),
            CarbonImmutable::parse($request->validated('date_to'), $timezone)->startOfDay(),
            $timezone,
            (int) $request->validated('party_size', 1),
        );

        return response()->json(['data' => $data]);
    }
}
