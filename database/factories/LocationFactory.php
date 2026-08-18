<?php

namespace Database\Factories;

use App\Domain\Location\Location;
use App\Domain\Location\LocationType;
use App\Domain\Organization\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->city(),
            'timezone' => fake()->randomElement(['UTC', 'Europe/Bucharest', 'Europe/London', 'America/New_York']),
            'type' => LocationType::Physical,
            'address' => fake()->address(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'status' => 'active',
        ];
    }
}
