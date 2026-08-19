<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\AuditLogger;
use App\Domain\ApiKey\ApiKey;
use App\Domain\Organization\Organization;
use App\Http\Controllers\Concerns\ResolvesOrganizationFromQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApiKey\StoreApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'API Keys')]
class ApiKeyController extends Controller
{
    use AuthorizesRequests, ResolvesOrganizationFromQuery;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    #[OA\Get(
        path: '/api/v1/api-keys',
        summary: 'List API keys for an organization (never returns the plaintext key)',
        tags: ['API Keys'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'organization_id', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'API keys list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $organization = $this->resolveOrganization($request);

        $this->authorize('viewAny', [ApiKey::class, $organization]);

        return ApiKeyResource::collection($organization->apiKeys()->orderByDesc('created_at')->get());
    }

    #[OA\Post(
        path: '/api/v1/api-keys',
        summary: 'Create an API key (§45) — the plaintext key is only ever shown in this response',
        tags: ['API Keys'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'API key created')],
    )]
    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        $organization = Organization::where('public_id', $request->validated('organization_id'))->firstOrFail();

        $this->authorize('create', [ApiKey::class, $organization]);

        [$plainTextKey, $prefix] = ApiKey::generatePlainTextKey();

        $apiKey = ApiKey::create([
            'organization_id' => $organization->id,
            'created_by_user_id' => $request->user()->id,
            'name' => $request->validated('name'),
            'key_hash' => ApiKey::hashKey($plainTextKey),
            'key_prefix' => $prefix,
            'scopes' => $request->validated('scopes'),
            'expires_at' => $request->validated('expires_at'),
        ]);

        $this->auditLogger->log('api_key.created', $apiKey, null, [
            'name' => $apiKey->name,
            'scopes' => $apiKey->scopes,
            'key_prefix' => $apiKey->key_prefix,
        ]);

        $payload = array_merge(
            (new ApiKeyResource($apiKey))->toArray($request),
            ['key' => $plainTextKey],
        );

        return response()->json(['data' => $payload], 201);
    }

    #[OA\Delete(
        path: '/api/v1/api-keys/{apiKey}',
        summary: 'Revoke an API key — kept for audit history, not hard-deleted',
        tags: ['API Keys'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'API key revoked')],
    )]
    public function destroy(ApiKey $apiKey): Response
    {
        $this->authorize('delete', $apiKey);

        $before = ['name' => $apiKey->name, 'scopes' => $apiKey->scopes, 'revoked_at' => null];

        $apiKey->update(['revoked_at' => now()]);

        $this->auditLogger->log('api_key.revoked', $apiKey, $before, ['revoked_at' => $apiKey->revoked_at->toIso8601String()]);

        return response()->noContent();
    }
}
