<?php

namespace Database\Factories;

use App\Domain\Organization\Organization;
use App\Domain\Resource\ResourceGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceGroup>
 */
class ResourceGroupFactory extends Factory
{
    protected $model = ResourceGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->words(2, true),
        ];
    }
}
