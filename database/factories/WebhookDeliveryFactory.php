<?php

namespace Database\Factories;

use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookDeliveryStatus;
use App\Domain\Webhook\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'event_type' => 'booking.created',
            'payload' => ['booking_id' => 'bkg_'.fake()->uuid()],
            'attempt' => 0,
            'status_code' => null,
            'response_body' => null,
            'duration_ms' => null,
            'status' => WebhookDeliveryStatus::Pending,
            'next_retry_at' => null,
        ];
    }
}
