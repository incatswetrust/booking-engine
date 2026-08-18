<?php

namespace Database\Factories;

use App\Domain\Organization\Organization;
use App\Domain\Service\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->randomElement(['Massage', 'Haircut', 'Personal Training', 'Photo Session']),
            'description' => fake()->sentence(),
            'duration_minutes' => fake()->randomElement([30, 45, 60, 90, 120]),
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'price' => fake()->randomFloat(2, 10, 200),
            'currency' => fake()->randomElement(['USD', 'EUR', 'GBP']),
            'status' => 'active',
        ];
    }
}
