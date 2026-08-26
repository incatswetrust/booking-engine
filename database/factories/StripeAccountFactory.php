<?php

namespace Database\Factories;

use App\Domain\Organization\Organization;
use App\Domain\Payment\StripeAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StripeAccount>
 */
class StripeAccountFactory extends Factory
{
    protected $model = StripeAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'stripe_account_id' => 'acct_'.Str::random(16),
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'connected_at' => now(),
        ];
    }
}
