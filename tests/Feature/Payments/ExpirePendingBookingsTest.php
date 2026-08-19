<?php

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use App\Models\User;
use Carbon\CarbonInterface;

function makeAwaitingPaymentBookingAt(Organization $organization, CarbonInterface $createdAt): Booking
{
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['payment_mode' => 'full']);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => User::factory()->create()->id,
        'status' => BookingStatus::AwaitingPayment,
    ]);

    // created_at isn't mass-assignable on Booking -- force it directly so
    // the "past its timeout" scenario is actually reproducible in a test.
    $booking->forceFill(['created_at' => $createdAt])->save();

    return $booking;
}

it('expires an awaiting_payment booking past the organization payment timeout, and fails its pending payment', function () {
    $organization = Organization::factory()->create(['settings' => ['payment_timeout_minutes' => 30]]);
    $booking = makeAwaitingPaymentBookingAt($organization, now()->subMinutes(31));
    $payment = Payment::factory()->for($booking)->create(['status' => PaymentStatus::Pending]);

    $this->artisan('bookings:expire-pending')->assertSuccessful();

    expect($booking->refresh()->status)->toBe(BookingStatus::Expired)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Failed);
});

it('does not touch a booking still within its payment timeout window', function () {
    $organization = Organization::factory()->create(['settings' => ['payment_timeout_minutes' => 30]]);
    $booking = makeAwaitingPaymentBookingAt($organization, now()->subMinutes(10));

    $this->artisan('bookings:expire-pending')->assertSuccessful();

    expect($booking->refresh()->status)->toBe(BookingStatus::AwaitingPayment);
});

it('respects a different payment_timeout_minutes per organization', function () {
    $organization = Organization::factory()->create(['settings' => ['payment_timeout_minutes' => 5]]);
    $booking = makeAwaitingPaymentBookingAt($organization, now()->subMinutes(6));

    $this->artisan('bookings:expire-pending')->assertSuccessful();

    expect($booking->refresh()->status)->toBe(BookingStatus::Expired);
});

it('does not touch bookings in other statuses', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);

    $confirmed = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => User::factory()->create()->id,
        'status' => BookingStatus::Confirmed,
    ]);
    $confirmed->forceFill(['created_at' => now()->subDays(2)])->save();

    $this->artisan('bookings:expire-pending')->assertSuccessful();

    expect($confirmed->refresh()->status)->toBe(BookingStatus::Confirmed);
});
