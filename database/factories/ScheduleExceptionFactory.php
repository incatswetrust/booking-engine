<?php

namespace Database\Factories;

use App\Domain\Resource\Resource;
use App\Domain\Schedule\ScheduleException;
use App\Domain\Schedule\ScheduleExceptionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleException>
 */
class ScheduleExceptionFactory extends Factory
{
    protected $model = ScheduleException::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'date' => fake()->unique()->dateTimeBetween('+1 day', '+60 days')->format('Y-m-d'),
            'type' => ScheduleExceptionType::Closed,
            'start_time' => null,
            'end_time' => null,
        ];
    }
}
