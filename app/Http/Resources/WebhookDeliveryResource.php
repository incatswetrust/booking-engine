<?php

namespace App\Http\Resources;

use App\Domain\Webhook\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WebhookDelivery $delivery */
        $delivery = $this->resource;

        return [
            'id' => $delivery->public_id,
            'webhook_endpoint_id' => $delivery->webhookEndpoint->public_id,
            'event_type' => $delivery->event_type,
            'attempt' => $delivery->attempt,
            'status_code' => $delivery->status_code,
            'response_body' => $delivery->response_body,
            'duration_ms' => $delivery->duration_ms,
            'status' => $delivery->status,
            'next_retry_at' => $delivery->next_retry_at,
            'created_at' => $delivery->created_at,
        ];
    }
}
