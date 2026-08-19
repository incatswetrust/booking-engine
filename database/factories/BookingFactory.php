<?php

namespace Database\Factories;

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 day', '+10 days');

        return [
            'organization_id' => Organization::factory(),
            'customer_id' => User::factory(),
            'service_id' => Service::factory(),
            'resource_id' => Resource::factory(),
            'location_id' => Location::factory(),
            'start_at' => $start,
            'end_at' => (clone $start)->modify('+1 hour'),
            'status' => BookingStatus::Confirmed,
            'price' => fake()->randomFloat(2, 10, 200),
            'currency' => 'USD',
            'notes' => null,
            'party_size' => 1,
        ];
    }
}
