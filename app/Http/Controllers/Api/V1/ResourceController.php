<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceGroup;
use App\Http\Controllers\Concerns\ResolvesOrganizationFromQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Resource\StoreResourceRequest;
use App\Http\Requests\Resource\UpdateResourceRequest;
use App\Http\Resources\ResourceResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Resources')]
class ResourceController extends Controller
{
    use AuthorizesRequests, ResolvesOrganizationFromQuery;

    #[OA\Get(
        path: '/api/v1/resources',
        summary: 'List resources for an organization',
        tags: ['Resources'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'organization_id', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Resources list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $organization = $this->resolveOrganization($request);

        $this->authorize('viewAny', [Resource::class, $organization]);

        return ResourceResource::collection(
            $organization->resources()->with('resourceGroup')->get()
        );
    }

    #[OA\Post(
        path: '/api/v1/resources',
        summary: 'Create a resource',
        tags: ['Resources'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'Resource created')],
    )]
    public function store(StoreResourceRequest $request): JsonResponse
    {
        $organization = Organization::where('public_id', $request->validated('organization_id'))->firstOrFail();

        $this->authorize('create', [Resource::class, $organization]);

        $location = Location::where('public_id', $request->validated('location_id'))->firstOrFail();

        $resourceGroup = $request->validated('resource_group_id')
            ? ResourceGroup::where('public_id', $request->validated('resource_group_id'))->firstOrFail()
            : null;

        $resource = $organization->resources()->create([
            'location_id' => $location->id,
            'resource_group_id' => $resourceGroup?->id,
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'type' => $request->validated('type'),
            'capacity' => $request->validated('capacity', 1),
            'metadata' => $request->validated('metadata', []),
            'status' => 'active',
        ]);

        return (new ResourceResource($resource->load('resourceGroup')))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/resources/{resource}',
        summary: 'Get a resource by its public id',
        tags: ['Resources'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Resource')],
    )]
    public function show(Resource $resource): ResourceResource
    {
        $this->authorize('view', $resource);

        return new ResourceResource($resource->load('resourceGroup'));
    }

    #[OA\Patch(
        path: '/api/v1/resources/{resource}',
        summary: 'Update a resource',
        tags: ['Resources'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Resource updated')],
    )]
    public function update(UpdateResourceRequest $request, Resource $resource): ResourceResource
    {
        $data = $request->validated();

        if (array_key_exists('location_id', $data)) {
            $data['location_id'] = Location::where('public_id', $data['location_id'])->firstOrFail()->id;
        }

        if (array_key_exists('resource_group_id', $data)) {
            $data['resource_group_id'] = $data['resource_group_id']
                ? ResourceGroup::where('public_id', $data['resource_group_id'])->firstOrFail()->id
                : null;
        }

        $resource->update($data);

        return new ResourceResource($resource->load('resourceGroup'));
    }

    #[OA\Delete(
        path: '/api/v1/resources/{resource}',
        summary: 'Delete a resource',
        tags: ['Resources'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Resource deleted')],
    )]
    public function destroy(Resource $resource): Response
    {
        $this->authorize('delete', $resource);

        $resource->delete();

        return response()->noContent();
    }
}
