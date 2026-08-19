<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\AuditLogger;
use App\Domain\Organization\Organization;
use App\Domain\Webhook\WebhookEndpoint;
use App\Domain\Webhook\WebhookEndpointStatus;
use App\Http\Controllers\Concerns\ResolvesOrganizationFromQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhook\StoreWebhookEndpointRequest;
use App\Http\Requests\Webhook\UpdateWebhookEndpointRequest;
use App\Http\Resources\WebhookEndpointResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Webhook Endpoints')]
class WebhookEndpointController extends Controller
{
    use AuthorizesRequests, ResolvesOrganizationFromQuery;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    #[OA\Get(
        path: '/api/v1/webhook-endpoints',
        summary: 'List webhook endpoints for an organization (never returns the secret)',
        tags: ['Webhook Endpoints'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'organization_id', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Webhook endpoints list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $organization = $this->resolveOrganization($request);

        $this->authorize('viewAny', [WebhookEndpoint::class, $organization]);

        return WebhookEndpointResource::collection($organization->webhookEndpoints()->orderByDesc('created_at')->get());
    }

    #[OA\Post(
        path: '/api/v1/webhook-endpoints',
        summary: 'Register a webhook endpoint (§41) — the signing secret is only ever shown in this response',
        tags: ['Webhook Endpoints'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'Webhook endpoint created')],
    )]
    public function store(StoreWebhookEndpointRequest $request): JsonResponse
    {
        $organization = Organization::where('public_id', $request->validated('organization_id'))->firstOrFail();

        $this->authorize('create', [WebhookEndpoint::class, $organization]);

        $secret = Str::random(40);

        $endpoint = WebhookEndpoint::create([
            'organization_id' => $organization->id,
            'created_by_user_id' => $request->user()->id,
            'url' => $request->validated('url'),
            'secret' => $secret,
            'events' => $request->validated('events'),
            'status' => WebhookEndpointStatus::Active,
        ]);

        $this->auditLogger->log('webhook_endpoint.created', $endpoint, null, [
            'url' => $endpoint->url,
            'events' => $endpoint->events,
        ]);

        $payload = array_merge((new WebhookEndpointResource($endpoint))->toArray($request), ['secret' => $secret]);

        return response()->json(['data' => $payload], 201);
    }

    #[OA\Patch(
        path: '/api/v1/webhook-endpoints/{webhookEndpoint}',
        summary: 'Update a webhook endpoint\'s URL, subscribed events, or status',
        tags: ['Webhook Endpoints'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Webhook endpoint updated')],
    )]
    public function update(UpdateWebhookEndpointRequest $request, WebhookEndpoint $webhookEndpoint): WebhookEndpointResource
    {
        $before = ['url' => $webhookEndpoint->url, 'events' => $webhookEndpoint->events, 'status' => $webhookEndpoint->status->value];

        $webhookEndpoint->update($request->validated());

        $this->auditLogger->log('webhook_endpoint.updated', $webhookEndpoint, $before, [
            'url' => $webhookEndpoint->url,
            'events' => $webhookEndpoint->events,
            'status' => $webhookEndpoint->status->value,
        ]);

        return new WebhookEndpointResource($webhookEndpoint);
    }

    #[OA\Delete(
        path: '/api/v1/webhook-endpoints/{webhookEndpoint}',
        summary: 'Remove a webhook endpoint',
        tags: ['Webhook Endpoints'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Webhook endpoint deleted')],
    )]
    public function destroy(WebhookEndpoint $webhookEndpoint): Response
    {
        $this->authorize('delete', $webhookEndpoint);

        $this->auditLogger->log('webhook_endpoint.deleted', $webhookEndpoint, [
            'url' => $webhookEndpoint->url,
            'events' => $webhookEndpoint->events,
        ], null);

        $webhookEndpoint->delete();

        return response()->noContent();
    }
}
