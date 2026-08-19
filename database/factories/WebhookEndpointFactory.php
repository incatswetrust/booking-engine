<?php

namespace Database\Factories;

use App\Domain\Organization\Organization;
use App\Domain\Webhook\WebhookEndpoint;
use App\Domain\Webhook\WebhookEndpointStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'created_by_user_id' => User::factory(),
            'url' => 'https://example.com/webhooks/'.Str::random(8),
            'secret' => Str::random(32),
            'events' => ['booking.created', 'booking.confirmed', 'booking.cancelled', 'payment.completed'],
            'status' => WebhookEndpointStatus::Active,
        ];
    }
}
