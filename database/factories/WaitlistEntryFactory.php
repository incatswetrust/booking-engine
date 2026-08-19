<?php

namespace Database\Factories;

use App\Domain\Service\Service;
use App\Domain\Waitlist\WaitlistEntry;
use App\Domain\Waitlist\WaitlistStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaitlistEntry>
 */
class WaitlistEntryFactory extends Factory
{
    protected $model = WaitlistEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => User::factory(),
            'service_id' => Service::factory(),
            'resource_id' => null,
            'desired_start_at' => fake()->dateTimeBetween('+1 day', '+10 days'),
            'status' => WaitlistStatus::Waiting,
        ];
    }
}
