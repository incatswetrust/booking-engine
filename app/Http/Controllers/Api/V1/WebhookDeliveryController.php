<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\Permission;
use App\Domain\Organization\Organization;
use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Errors\ApiException;
use App\Http\Errors\ErrorCode;
use App\Http\Resources\WebhookDeliveryResource;
use App\Jobs\DeliverWebhook;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Webhook Deliveries')]
class WebhookDeliveryController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/v1/webhook-deliveries',
        summary: 'List webhook delivery attempts, optionally filtered by endpoint (§42)',
        tags: ['Webhook Deliveries'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'webhook_endpoint_id', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Webhook deliveries list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        // §61/§71: no platform-admin bypass -- see BookingController::index().
        $readableOrgIds = $user->organizations
            ->filter(fn (Organization $org) => $user->hasPermissionTo(Permission::IntegrationsManage, $org))
            ->pluck('id');

        // whereIn on an empty collection compiles to an always-false
        // clause, so a user with no manageable organizations correctly
        // sees nothing without a special case here.
        $query = WebhookDelivery::query()
            ->with('webhookEndpoint')
            ->whereHas('webhookEndpoint', fn ($q) => $q->whereIn('organization_id', $readableOrgIds));

        if ($request->filled('webhook_endpoint_id')) {
            $query->whereHas('webhookEndpoint', fn ($q) => $q->where('public_id', $request->query('webhook_endpoint_id')));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return WebhookDeliveryResource::collection($query->orderByDesc('created_at')->cursorPaginate(20)->withQueryString());
    }

    #[OA\Post(
        path: '/api/v1/webhook-deliveries/{webhookDelivery}/retry',
        summary: 'Manually retry a failed webhook delivery (§43)',
        tags: ['Webhook Deliveries'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Retry queued'),
            new OA\Response(response: 422, description: 'Delivery is not in a retryable state'),
        ],
    )]
    public function retry(WebhookDelivery $webhookDelivery): WebhookDeliveryResource
    {
        $this->authorize('retry', $webhookDelivery);

        if ($webhookDelivery->status !== WebhookDeliveryStatus::Failed) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                "A delivery with status \"{$webhookDelivery->status->value}\" cannot be retried.",
                422,
            );
        }

        $webhookDelivery->update(['status' => WebhookDeliveryStatus::Pending, 'next_retry_at' => null]);

        DeliverWebhook::dispatch($webhookDelivery->id);

        return new WebhookDeliveryResource($webhookDelivery->refresh());
    }
}
