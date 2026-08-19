<?php

namespace Database\Factories;

use App\Domain\Booking\BookingHold;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingHold>
 */
class BookingHoldFactory extends Factory
{
    protected $model = BookingHold::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 day', '+10 days');

        return [
            'resource_id' => Resource::factory(),
            'service_id' => Service::factory(),
            'customer_id' => User::factory(),
            'start_at' => $start,
            'end_at' => (clone $start)->modify('+1 hour'),
            'expires_at' => now()->addMinutes(10),
        ];
    }
}
