<?php

use App\Application\Services\StripeGateway;
use App\Domain\Auth\Role;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use App\Domain\Service\Service;
use App\Models\User;
use Stripe\Refund;

it('lets a stricter service cancellation_policy override the organization default notice window', function () {
    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldReceive('refund')->once();
    });

    // Org default is 24h free-cancellation notice, but this service
    // requires 48h -- cancelling 30h ahead is within the org's window
    // but outside the service's own, stricter one.
    $organization = Organization::factory()->create(['settings' => ['cancellation_notice_minutes' => 1440, 'late_cancellation_refund_percent' => 50]]);
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['cancellation_policy' => ['notice_minutes' => 2880]]);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => now()->addHours(30),
        'end_at' => now()->addHours(31),
    ]);

    Payment::factory()->for($booking)->create(['amount' => 100, 'amount_refunded' => 0, 'status' => PaymentStatus::Paid]);

    $response = $this->actingAs($customer, 'sanctum')->postJson("/api/v1/bookings/{$booking->public_id}/cancel")
        ->assertOk();

    // Late (per the service's own 48h policy) -- 50% refund from the
    // org's late_cancellation_refund_percent, since the service didn't
    // override that part.
    expect($response->json('meta.free_cancellation'))->toBeFalse();
    expect((float) $booking->refresh()->payments->first()->amount_refunded)->toBe(50.0);
});

it('lets a service override just refund_percent, keeping the organization\'s notice window', function () {
    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldReceive('refund')->once();
    });

    $organization = Organization::factory()->create(['settings' => ['cancellation_notice_minutes' => 1440, 'late_cancellation_refund_percent' => 50]]);
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['cancellation_policy' => ['refund_percent' => 10]]);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        // Within 24h -- late per the (unmodified, inherited) org window.
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
    ]);

    Payment::factory()->for($booking)->create(['amount' => 100, 'amount_refunded' => 0, 'status' => PaymentStatus::Paid]);

    $this->actingAs($customer, 'sanctum')->postJson("/api/v1/bookings/{$booking->public_id}/cancel")->assertOk();

    expect((float) $booking->refresh()->payments->first()->amount_refunded)->toBe(10.0);
});

it('falls back to the organization\'s policy when the service has none', function () {
    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldReceive('refund')->once();
    });

    $organization = Organization::factory()->create(['settings' => ['cancellation_notice_minutes' => 1440, 'late_cancellation_refund_percent' => 50]]);
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);

    expect($service->cancellation_policy)->toBeNull();

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
    ]);

    Payment::factory()->for($booking)->create(['amount' => 100, 'amount_refunded' => 0, 'status' => PaymentStatus::Paid]);

    $this->actingAs($customer, 'sanctum')->postJson("/api/v1/bookings/{$booking->public_id}/cancel")->assertOk();

    expect((float) $booking->refresh()->payments->first()->amount_refunded)->toBe(50.0);
});

it('accepts a cancellation_policy when creating or updating a service', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $response = $this->postJson('/api/v1/services', [
        'organization_id' => $organization->public_id,
        'name' => 'Strict Service',
        'duration_minutes' => 60,
        'price' => 100,
        'currency' => 'USD',
        'cancellation_policy' => ['notice_minutes' => 2880, 'refund_percent' => 10],
    ])->assertCreated();

    expect($response->json('data.cancellation_policy.notice_minutes'))->toBe(2880);

    $service = Service::firstOrFail();

    $this->patchJson("/api/v1/services/{$service->public_id}", [
        'cancellation_policy' => ['notice_minutes' => 1440],
    ])
        ->assertOk()
        ->assertJsonPath('data.cancellation_policy.notice_minutes', 1440);
});
