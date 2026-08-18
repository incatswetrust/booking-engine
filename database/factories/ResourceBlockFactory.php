<?php

namespace Database\Factories;

use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceBlock;
use App\Domain\Resource\ResourceBlockReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceBlock>
 */
class ResourceBlockFactory extends Factory
{
    protected $model = ResourceBlock::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 day', '+10 days');

        return [
            'resource_id' => Resource::factory(),
            'starts_at' => $start,
            'ends_at' => (clone $start)->modify('+3 hours'),
            'reason' => ResourceBlockReason::Maintenance,
            'notes' => fake()->sentence(),
        ];
    }
}
