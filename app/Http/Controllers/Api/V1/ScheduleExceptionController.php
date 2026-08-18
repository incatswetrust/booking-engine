<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Resource\Resource;
use App\Domain\Schedule\ScheduleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\StoreScheduleExceptionRequest;
use App\Http\Resources\ScheduleExceptionResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Schedules')]
class ScheduleExceptionController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/v1/resources/{resource}/schedule-exceptions',
        summary: "List a resource's schedule exceptions",
        tags: ['Schedules'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Schedule exceptions')],
    )]
    public function index(Resource $resource): AnonymousResourceCollection
    {
        $this->authorize('view', $resource);

        return ScheduleExceptionResource::collection(
            $resource->scheduleExceptions()->orderBy('date')->get()
        );
    }

    #[OA\Post(
        path: '/api/v1/resources/{resource}/schedule-exceptions',
        summary: 'Add a schedule exception (closed day or custom hours) for a resource',
        tags: ['Schedules'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'Schedule exception created')],
    )]
    public function store(StoreScheduleExceptionRequest $request, Resource $resource): JsonResponse
    {
        $exception = $resource->scheduleExceptions()->create($request->validated());

        return (new ScheduleExceptionResource($exception))->response()->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/api/v1/resources/{resource}/schedule-exceptions/{scheduleException}',
        summary: 'Remove a schedule exception',
        tags: ['Schedules'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Schedule exception deleted')],
    )]
    public function destroy(Resource $resource, ScheduleException $scheduleException): Response
    {
        $this->authorize('update', $resource);

        abort_if($scheduleException->resource_id !== $resource->id, 404);

        $scheduleException->delete();

        return response()->noContent();
    }
}
