<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Http\Controllers\Concerns\ResolvesOrganizationFromQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Location\StoreLocationRequest;
use App\Http\Requests\Location\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Locations')]
class LocationController extends Controller
{
    use AuthorizesRequests, ResolvesOrganizationFromQuery;

    #[OA\Get(
        path: '/api/v1/locations',
        summary: 'List locations for an organization',
        tags: ['Locations'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'organization_id', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Locations list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $organization = $this->resolveOrganization($request);

        $this->authorize('viewAny', [Location::class, $organization]);

        return LocationResource::collection($organization->locations()->get());
    }

    #[OA\Post(
        path: '/api/v1/locations',
        summary: 'Create a location',
        tags: ['Locations'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'Location created')],
    )]
    public function store(StoreLocationRequest $request): JsonResponse
    {
        $organization = Organization::where('public_id', $request->validated('organization_id'))->firstOrFail();

        $this->authorize('create', [Location::class, $organization]);

        $location = $organization->locations()->create([
            'name' => $request->validated('name'),
            'timezone' => $request->validated('timezone'),
            'type' => $request->validated('type', 'physical'),
            'address' => $request->validated('address'),
            'latitude' => $request->validated('latitude'),
            'longitude' => $request->validated('longitude'),
            'status' => 'active',
        ]);

        return (new LocationResource($location))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/locations/{location}',
        summary: 'Get a location by its public id',
        tags: ['Locations'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Location')],
    )]
    public function show(Location $location): LocationResource
    {
        $this->authorize('view', $location);

        return new LocationResource($location);
    }

    #[OA\Patch(
        path: '/api/v1/locations/{location}',
        summary: 'Update a location',
        tags: ['Locations'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Location updated')],
    )]
    public function update(UpdateLocationRequest $request, Location $location): LocationResource
    {
        $location->update($request->validated());

        return new LocationResource($location);
    }

    #[OA\Delete(
        path: '/api/v1/locations/{location}',
        summary: 'Delete a location',
        tags: ['Locations'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Location deleted')],
    )]
    public function destroy(Location $location): Response
    {
        $this->authorize('delete', $location);

        $location->delete();

        return response()->noContent();
    }
}
