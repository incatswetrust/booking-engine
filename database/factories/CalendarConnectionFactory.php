<?php

namespace Database\Factories;

use App\Domain\Calendar\CalendarConnection;
use App\Domain\Calendar\CalendarConnectionStatus;
use App\Domain\Resource\Resource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CalendarConnection>
 */
class CalendarConnectionFactory extends Factory
{
    protected $model = CalendarConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'created_by_user_id' => User::factory(),
            'provider' => 'google',
            'external_calendar_id' => 'primary',
            'access_token' => Str::random(40),
            'refresh_token' => Str::random(40),
            'token_expires_at' => now()->addHour(),
            'status' => CalendarConnectionStatus::Active,
            'busy_periods' => [],
            'busy_periods_synced_at' => null,
        ];
    }
}
