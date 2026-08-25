<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\AnalyticsService;
use App\Domain\Auth\Role;
use App\Domain\Organization\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\OrganizationStatisticsRequest;
use App\Http\Requests\Organization\StoreOrganizationRequest;
use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Organizations')]
class OrganizationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly AnalyticsService $analytics) {}

    #[OA\Get(
        path: '/api/v1/organizations',
        summary: 'List organizations the current user belongs to',
        tags: ['Organizations'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Organizations list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        // §61/§71: a platform admin is not special here -- their only
        // elevated capability is the /admin/* surface. Listing every
        // organization on the platform would leak other tenants'
        // business content into the regular API.
        return OrganizationResource::collection($request->user()->organizations()->get());
    }

    #[OA\Post(
        path: '/api/v1/organizations',
        summary: 'Create a new organization (the creator becomes its owner)',
        tags: ['Organizations'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'Organization created')],
    )]
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $organizationId = DB::transaction(function () use ($request) {
            $organization = Organization::create([
                'name' => $request->validated('name'),
                'slug' => $request->validated('slug'),
                'timezone' => $request->validated('timezone'),
                'currency' => strtoupper((string) $request->validated('currency')),
                'settings' => array_merge(Organization::defaultSettings(), $request->validated('settings', [])),
            ]);

            $organization->users()->attach($request->user(), ['role' => Role::OrganizationOwner->value]);

            return $organization->id;
        });

        $organization = $request->user()->organizations()->findOrFail($organizationId);

        return (new OrganizationResource($organization))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/organizations/{organization}',
        summary: 'Get an organization by its public id',
        tags: ['Organizations'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Organization'),
            new OA\Response(response: 403, description: 'Not a member of this organization'),
        ],
    )]
    public function show(Request $request, Organization $organization): OrganizationResource
    {
        $this->authorize('view', $organization);

        // Route-model binding resolves $organization with no pivot
        // attached, so my_role would come back null for every caller --
        // re-resolve it through the user's own membership (authorize()
        // above already guarantees one exists by this point).
        $withPivot = $request->user()->organizations()->find($organization->id);

        return new OrganizationResource($withPivot ?? $organization);
    }

    #[OA\Patch(
        path: '/api/v1/organizations/{organization}',
        summary: 'Update an organization',
        tags: ['Organizations'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Organization updated'),
            new OA\Response(response: 403, description: 'Missing organizations.update permission'),
        ],
    )]
    public function update(UpdateOrganizationRequest $request, Organization $organization): OrganizationResource
    {
        $organization->update($request->validated());

        return new OrganizationResource($organization);
    }

    #[OA\Get(
        path: '/api/v1/organizations/{organization}/statistics',
        summary: 'Booking/revenue statistics for an organization (§5, Organization Owner "видеть статистику")',
        tags: ['Organizations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'), description: 'Defaults to 30 days ago'),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'), description: 'Defaults to today'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Booking counts by status, revenue, cancellation rate, top services/resources'),
            new OA\Response(response: 403, description: 'Missing analytics.read permission (Owner-only)'),
        ],
    )]
    public function statistics(OrganizationStatisticsRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('viewStatistics', $organization);

        $dateFrom = $request->filled('date_from')
            ? CarbonImmutable::parse($request->validated('date_from'))->startOfDay()
            : CarbonImmutable::now()->subDays(30)->startOfDay();

        $dateTo = $request->filled('date_to')
            ? CarbonImmutable::parse($request->validated('date_to'))->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        return response()->json(['data' => $this->analytics->forOrganization($organization, $dateFrom, $dateTo)]);
    }
}
