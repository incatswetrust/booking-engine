<?php

namespace Database\Factories;

use App\Domain\ApiKey\ApiKey;
use App\Domain\Organization\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$plainTextKey, $prefix] = ApiKey::generatePlainTextKey();

        return [
            'organization_id' => Organization::factory(),
            'created_by_user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'key_hash' => ApiKey::hashKey($plainTextKey),
            'key_prefix' => $prefix,
            'scopes' => ['bookings:read'],
            'expires_at' => null,
            'revoked_at' => null,
            'last_used_at' => null,
        ];
    }
}
