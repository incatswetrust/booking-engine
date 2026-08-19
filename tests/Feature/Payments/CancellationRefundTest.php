<?php

use App\Application\Services\StripeGateway;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use App\Models\User;
use Stripe\Exception\ApiConnectionException;
use Stripe\Refund;

function fakeRefundResult(string $id = 're_cancel_test'): Refund
{
    return Refund::constructFrom(['id' => $id, 'status' => 'succeeded']);
}

it('fully refunds a paid payment when cancelling within the free cancellation window (§28)', function () {
    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldReceive('refund')->once()->andReturn(fakeRefundResult());
    });

    $organization = Organization::factory()->create(['settings' => ['cancellation_notice_minutes' => 1440]]);
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => now()->addDays(5),
        'end_at' => now()->addDays(5)->addHour(),
    ]);

    $payment = Payment::factory()->for($booking)->create([
        'amount' => 100,
        'amount_refunded' => 0,
        'status' => PaymentStatus::Paid,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/cancel")
        ->assertOk()
        ->assertJsonPath('meta.free_cancellation', true);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Refunded)
        ->and((float) $payment->amount_refunded)->toBe(100.0);
});

it('only refunds late_cancellation_refund_percent of a paid payment on a late cancellation (§28)', function () {
    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldReceive('refund')->once()->andReturn(fakeRefundResult());
    });

    $organization = Organization::factory()->create([
        'settings' => ['cancellation_notice_minutes' => 1440, 'late_cancellation_refund_percent' => 50],
    ]);
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => now()->addHour(),
        'end_at' => now()->addHours(2),
    ]);

    $payment = Payment::factory()->for($booking)->create([
        'amount' => 100,
        'amount_refunded' => 0,
        'status' => PaymentStatus::Paid,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/cancel")
        ->assertOk()
        ->assertJsonPath('meta.free_cancellation', false);

    expect($payment->refresh()->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and((float) $payment->amount_refunded)->toBe(50.0);
});

it('cancels cleanly with no refund attempt when the booking has no paid payment', function () {
    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldNotReceive('refund');
    });

    $organization = Organization::factory()->create();
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => now()->addDays(5),
        'end_at' => now()->addDays(5)->addHour(),
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/cancel")
        ->assertOk();
});

it('still cancels the booking even if the automatic refund fails', function () {
    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldReceive('refund')->once()->andThrow(new ApiConnectionException('network down'));
    });

    $organization = Organization::factory()->create(['settings' => ['cancellation_notice_minutes' => 1440]]);
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => now()->addDays(5),
        'end_at' => now()->addDays(5)->addHour(),
    ]);

    $payment = Payment::factory()->for($booking)->create([
        'amount' => 100,
        'amount_refunded' => 0,
        'status' => PaymentStatus::Paid,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    // The refund attempt failed, so the payment is untouched -- staff can retry manually.
    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid);
});
