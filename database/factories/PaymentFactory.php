<?php

namespace Database\Factories;

use App\Domain\Booking\Booking;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_'.fake()->unique()->regexify('[A-Za-z0-9]{24}'),
            'amount' => fake()->randomFloat(2, 10, 200),
            'amount_refunded' => 0,
            'currency' => 'USD',
            'status' => PaymentStatus::Pending,
            'failure_reason' => null,
            'paid_at' => null,
        ];
    }
}
