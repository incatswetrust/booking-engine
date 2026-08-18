<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceBlock;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResourceBlock\StoreResourceBlockRequest;
use App\Http\Resources\ResourceBlockResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Resource Blocks')]
class ResourceBlockController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/v1/resource-blocks',
        summary: 'List blocks for a resource',
        tags: ['Resource Blocks'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'resource_id', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Resource blocks list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $resource = $this->resolveResource($request);

        $this->authorize('view', $resource);

        return ResourceBlockResource::collection(
            $resource->blocks()->orderBy('starts_at')->get()
        );
    }

    #[OA\Post(
        path: '/api/v1/resource-blocks',
        summary: 'Block a resource for a time period',
        tags: ['Resource Blocks'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'Resource block created')],
    )]
    public function store(StoreResourceBlockRequest $request): JsonResponse
    {
        $resource = Resource::where('public_id', $request->validated('resource_id'))->firstOrFail();

        $this->authorize('update', $resource);

        $block = $resource->blocks()->create([
            'starts_at' => $request->validated('starts_at'),
            'ends_at' => $request->validated('ends_at'),
            'reason' => $request->validated('reason'),
            'notes' => $request->validated('notes'),
        ]);

        return (new ResourceBlockResource($block))->response()->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/api/v1/resource-blocks/{resourceBlock}',
        summary: 'Remove a resource block',
        tags: ['Resource Blocks'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Resource block deleted')],
    )]
    public function destroy(ResourceBlock $resourceBlock): Response
    {
        $this->authorize('update', $resourceBlock->resource);

        $resourceBlock->delete();

        return response()->noContent();
    }

    private function resolveResource(Request $request): Resource
    {
        $publicId = $request->query('resource_id');

        if (! is_string($publicId) || $publicId === '') {
            throw ValidationException::withMessages([
                'resource_id' => ['The resource_id query parameter is required.'],
            ]);
        }

        return Resource::where('public_id', $publicId)->firstOrFail();
    }
}
