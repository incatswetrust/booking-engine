<?php

namespace Database\Factories;

use App\Infrastructure\Idempotency\IdempotencyKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdempotencyKey>
 */
class IdempotencyKeyFactory extends Factory
{
    protected $model = IdempotencyKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->uuid(),
            'user_id' => User::factory(),
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'response_status' => 201,
            'response_body' => json_encode(['data' => []]),
            'expires_at' => now()->addDay(),
        ];
    }
}
