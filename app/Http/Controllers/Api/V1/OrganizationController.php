<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\Role;
use App\Domain\Organization\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationRequest;
use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
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

    #[OA\Get(
        path: '/api/v1/organizations',
        summary: 'List organizations the current user belongs to',
        tags: ['Organizations'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Organizations list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $organizations = $user->is_platform_admin
            ? Organization::query()->get()
            : $user->organizations()->get();

        return OrganizationResource::collection($organizations);
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
    public function show(Organization $organization): OrganizationResource
    {
        $this->authorize('view', $organization);

        return new OrganizationResource($organization);
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
}
