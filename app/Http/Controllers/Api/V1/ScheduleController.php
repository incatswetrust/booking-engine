<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\AvailabilityCache;
use App\Domain\Resource\Resource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\UpdateScheduleRequest;
use App\Http\Resources\ScheduleRuleResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Schedules')]
class ScheduleController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly AvailabilityCache $availabilityCache) {}

    #[OA\Get(
        path: '/api/v1/resources/{resource}/schedule',
        summary: "Get a resource's weekly schedule rules",
        tags: ['Schedules'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Schedule rules')],
    )]
    public function index(Resource $resource): AnonymousResourceCollection
    {
        $this->authorize('view', $resource);

        return ScheduleRuleResource::collection(
            $resource->scheduleRules()->orderBy('day_of_week')->orderBy('start_time')->get()
        );
    }

    #[OA\Put(
        path: '/api/v1/resources/{resource}/schedule',
        summary: "Replace a resource's entire weekly schedule",
        tags: ['Schedules'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Schedule replaced')],
    )]
    public function update(UpdateScheduleRequest $request, Resource $resource): AnonymousResourceCollection
    {
        DB::transaction(function () use ($request, $resource) {
            $resource->scheduleRules()->delete();

            foreach ($request->validated('rules') as $rule) {
                $resource->scheduleRules()->create([
                    'day_of_week' => $rule['day_of_week'],
                    'start_time' => $rule['start_time'],
                    'end_time' => $rule['end_time'],
                    'valid_from' => $rule['valid_from'] ?? null,
                    'valid_until' => $rule['valid_until'] ?? null,
                ]);
            }
        });

        $this->availabilityCache->forgetForResource($resource);

        return ScheduleRuleResource::collection(
            $resource->scheduleRules()->orderBy('day_of_week')->orderBy('start_time')->get()
        );
    }
}
