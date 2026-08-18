<?php

namespace Database\Factories;

use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<resource>
 */
class ResourceFactory extends Factory
{
    protected $model = Resource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'location_id' => Location::factory(),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'type' => fake()->randomElement(['room', 'court', 'vehicle', 'equipment', 'person']),
            'capacity' => 1,
            'status' => 'active',
            'metadata' => [],
        ];
    }
}
