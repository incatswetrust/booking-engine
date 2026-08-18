<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organization\Organization;
use App\Domain\Resource\ResourceGroup;
use App\Http\Controllers\Concerns\ResolvesOrganizationFromQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResourceGroup\StoreResourceGroupRequest;
use App\Http\Requests\ResourceGroup\UpdateResourceGroupRequest;
use App\Http\Resources\ResourceGroupResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Resource Groups')]
class ResourceGroupController extends Controller
{
    use AuthorizesRequests, ResolvesOrganizationFromQuery;

    #[OA\Get(
        path: '/api/v1/resource-groups',
        summary: 'List resource groups for an organization',
        tags: ['Resource Groups'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'organization_id', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Resource groups list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $organization = $this->resolveOrganization($request);

        $this->authorize('viewAny', [ResourceGroup::class, $organization]);

        return ResourceGroupResource::collection($organization->resourceGroups()->get());
    }

    #[OA\Post(
        path: '/api/v1/resource-groups',
        summary: 'Create a resource group',
        tags: ['Resource Groups'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'Resource group created')],
    )]
    public function store(StoreResourceGroupRequest $request): JsonResponse
    {
        $organization = Organization::where('public_id', $request->validated('organization_id'))->firstOrFail();

        $this->authorize('create', [ResourceGroup::class, $organization]);

        $group = $organization->resourceGroups()->create([
            'name' => $request->validated('name'),
        ]);

        return (new ResourceGroupResource($group))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/resource-groups/{resourceGroup}',
        summary: 'Get a resource group by its public id',
        tags: ['Resource Groups'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Resource group')],
    )]
    public function show(ResourceGroup $resourceGroup): ResourceGroupResource
    {
        $this->authorize('view', $resourceGroup);

        return new ResourceGroupResource($resourceGroup);
    }

    #[OA\Patch(
        path: '/api/v1/resource-groups/{resourceGroup}',
        summary: 'Update a resource group',
        tags: ['Resource Groups'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Resource group updated')],
    )]
    public function update(UpdateResourceGroupRequest $request, ResourceGroup $resourceGroup): ResourceGroupResource
    {
        $resourceGroup->update($request->validated());

        return new ResourceGroupResource($resourceGroup);
    }

    #[OA\Delete(
        path: '/api/v1/resource-groups/{resourceGroup}',
        summary: 'Delete a resource group',
        tags: ['Resource Groups'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Resource group deleted')],
    )]
    public function destroy(ResourceGroup $resourceGroup): Response
    {
        $this->authorize('delete', $resourceGroup);

        $resourceGroup->delete();

        return response()->noContent();
    }
}
