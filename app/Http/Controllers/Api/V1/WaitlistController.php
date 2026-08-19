<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\Permission;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use App\Domain\Waitlist\WaitlistEntry;
use App\Domain\Waitlist\WaitlistStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Waitlist\StoreWaitlistEntryRequest;
use App\Http\Resources\WaitlistEntryResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Waitlist')]
class WaitlistController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/v1/waitlist',
        summary: 'List waitlist entries visible to the current user (own entries + orgs they can read)',
        tags: ['Waitlist'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Waitlist entries')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = WaitlistEntry::query()->with(['customer', 'service', 'resource']);

        if (! $user->is_platform_admin) {
            $readableOrgIds = $user->organizations
                ->filter(fn (Organization $org) => $user->hasPermissionTo(Permission::BookingsRead, $org))
                ->pluck('id');

            $query->where(function ($q) use ($user, $readableOrgIds) {
                $q->where('customer_id', $user->id);

                if ($readableOrgIds->isNotEmpty()) {
                    $q->orWhereHas('service', fn ($s) => $s->whereIn('organization_id', $readableOrgIds));
                }
            });
        }

        return WaitlistEntryResource::collection($query->orderByDesc('created_at')->cursorPaginate(20)->withQueryString());
    }

    #[OA\Post(
        path: '/api/v1/waitlist',
        summary: 'Join the waitlist for a slot that is currently taken (§29)',
        tags: ['Waitlist'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'Waitlist entry created')],
    )]
    public function store(StoreWaitlistEntryRequest $request): JsonResponse
    {
        $service = Service::where('public_id', $request->validated('service_id'))->firstOrFail();

        $resource = $request->filled('resource_id')
            ? Resource::where('public_id', $request->validated('resource_id'))->firstOrFail()
            : null;

        $entry = WaitlistEntry::create([
            'customer_id' => $request->user()->id,
            'service_id' => $service->id,
            'resource_id' => $resource?->id,
            'desired_start_at' => $request->validated('desired_start_at'),
            'status' => WaitlistStatus::Waiting,
        ]);

        return (new WaitlistEntryResource($entry))->response()->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/api/v1/waitlist/{waitlistEntry}',
        summary: 'Leave the waitlist',
        tags: ['Waitlist'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Waitlist entry removed')],
    )]
    public function destroy(WaitlistEntry $waitlistEntry): Response
    {
        $this->authorize('delete', $waitlistEntry);

        $waitlistEntry->delete();

        return response()->noContent();
    }
}
