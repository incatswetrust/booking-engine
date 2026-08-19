<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use App\Http\Controllers\Concerns\ResolvesOrganizationFromQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Service\StoreServiceRequest;
use App\Http\Requests\Service\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Services')]
class ServiceController extends Controller
{
    use AuthorizesRequests, ResolvesOrganizationFromQuery;

    #[OA\Get(
        path: '/api/v1/services',
        summary: 'List services for an organization',
        tags: ['Services'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'organization_id', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Services list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $organization = $this->resolveOrganization($request);

        $this->authorize('viewAny', [Service::class, $organization]);

        return ServiceResource::collection(
            $organization->services()->with('resources')->get()
        );
    }

    #[OA\Post(
        path: '/api/v1/services',
        summary: 'Create a service, optionally linking it to resources',
        tags: ['Services'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'Service created')],
    )]
    public function store(StoreServiceRequest $request): JsonResponse
    {
        $organization = Organization::where('public_id', $request->validated('organization_id'))->firstOrFail();

        $this->authorize('create', [Service::class, $organization]);

        $service = $organization->services()->create([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'duration_minutes' => $request->validated('duration_minutes'),
            'buffer_before_minutes' => $request->validated('buffer_before_minutes', 0),
            'buffer_after_minutes' => $request->validated('buffer_after_minutes', 0),
            'price' => $request->validated('price'),
            'currency' => strtoupper((string) $request->validated('currency')),
            'status' => 'active',
        ]);

        $this->syncResources($service, $request->validated('resource_ids'));

        return (new ServiceResource($service->load('resources')))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/services/{service}',
        summary: 'Get a service by its public id',
        tags: ['Services'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Service')],
    )]
    public function show(Service $service): ServiceResource
    {
        $this->authorize('view', $service);

        return new ServiceResource($service->load('resources'));
    }

    #[OA\Patch(
        path: '/api/v1/services/{service}',
        summary: 'Update a service, optionally re-syncing its linked resources',
        tags: ['Services'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Service updated')],
    )]
    public function update(UpdateServiceRequest $request, Service $service): ServiceResource
    {
        $data = $request->validated();
        $resourceIds = $data['resource_ids'] ?? null;
        unset($data['resource_ids']);

        $service->update($data);

        if ($resourceIds !== null) {
            $this->syncResources($service, $resourceIds);
        }

        return new ServiceResource($service->load('resources'));
    }

    #[OA\Delete(
        path: '/api/v1/services/{service}',
        summary: 'Delete a service',
        tags: ['Services'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Service deleted')],
    )]
    public function destroy(Service $service): Response
    {
        $this->authorize('delete', $service);

        $service->delete();

        return response()->noContent();
    }

    /**
     * @param  array<int, string>|null  $publicIds
     */
    private function syncResources(Service $service, ?array $publicIds): void
    {
        if ($publicIds === null) {
            return;
        }

        $ids = Resource::whereIn('public_id', $publicIds)->pluck('id');

        $service->resources()->sync($ids);
    }
}
