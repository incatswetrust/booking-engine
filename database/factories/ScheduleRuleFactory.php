<?php

namespace Database\Factories;

use App\Domain\Resource\Resource;
use App\Domain\Schedule\ScheduleRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleRule>
 */
class ScheduleRuleFactory extends Factory
{
    protected $model = ScheduleRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'valid_from' => null,
            'valid_until' => null,
        ];
    }
}
