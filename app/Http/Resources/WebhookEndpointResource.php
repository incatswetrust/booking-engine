<?php

namespace App\Http\Resources;

use App\Domain\Webhook\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never includes the secret -- it's only ever returned once, in the raw
 * JSON response WebhookEndpointController::store() builds directly.
 */
class WebhookEndpointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = $this->resource;

        return [
            'id' => $endpoint->public_id,
            'organization_id' => $endpoint->organization->public_id,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'status' => $endpoint->status,
            'created_at' => $endpoint->created_at,
            'updated_at' => $endpoint->updated_at,
        ];
    }
}
