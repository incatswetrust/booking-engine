<?php

namespace App\Listeners;

use App\Domain\Organization\Organization;
use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookDeliveryStatus;
use App\Domain\Webhook\WebhookEventType;
use App\Jobs\DeliverWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Shared by the four concrete per-event listeners below (one per §41
 * subscription type) rather than one listener with four handle()
 * methods -- Laravel's event auto-discovery only picks up a method
 * literally named handle() with a single event type-hint, so getting
 * "just works via auto-discovery" for all four still means one class
 * per event, same as the booking notification listeners.
 */
abstract class DispatchesWebhookDeliveries implements ShouldQueue
{
    public string $queue = 'webhooks';

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function dispatchFor(WebhookEventType $eventType, array $payload): void
    {
        $organizationPublicId = $payload['organization_id'] ?? null;

        if ($organizationPublicId === null) {
            return;
        }

        $organization = Organization::where('public_id', $organizationPublicId)->first();

        if ($organization === null) {
            return;
        }

        $organization->webhookEndpoints
            ->filter(fn ($endpoint) => $endpoint->isSubscribedTo($eventType))
            ->each(function ($endpoint) use ($eventType, $payload) {
                $delivery = WebhookDelivery::create([
                    'webhook_endpoint_id' => $endpoint->id,
                    'event_type' => $eventType->value,
                    'payload' => $payload,
                    'status' => WebhookDeliveryStatus::Pending,
                ]);

                DeliverWebhook::dispatch($delivery->id);
            });
    }
}
