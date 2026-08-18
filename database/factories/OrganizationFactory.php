<?php

namespace Database\Factories;

use App\Domain\Organization\Organization;
use App\Domain\Organization\OrganizationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'timezone' => fake()->randomElement(['UTC', 'Europe/Bucharest', 'Europe/London', 'America/New_York']),
            'currency' => fake()->randomElement(['USD', 'EUR', 'GBP']),
            'status' => OrganizationStatus::Active,
            'settings' => Organization::defaultSettings(),
        ];
    }
}
