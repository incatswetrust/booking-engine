<?php

use App\Domain\Auth\Role;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Models\User;

function makeBookingIn(Organization $organization, BookingStatus $status = BookingStatus::Confirmed, ?User $customer = null): Booking
{
    [$resource, $service] = makeBookableResource($organization);

    return Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => ($customer ?? User::factory()->create())->id,
        'status' => $status,
    ]);
}

it('lets staff check a customer in and complete the booking', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationManager);
    $booking = makeBookingIn($organization, BookingStatus::Confirmed);

    $this->postJson("/api/v1/bookings/{$booking->public_id}/check-in")
        ->assertOk()
        ->assertJsonPath('data.status', 'checked_in');

    $this->postJson("/api/v1/bookings/{$booking->public_id}/complete")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');
});

it('forbids a customer from checking in their own booking', function () {
    $organization = Organization::factory()->create();
    $customer = User::factory()->create();
    $booking = makeBookingIn($organization, BookingStatus::Confirmed, $customer);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/check-in")
        ->assertStatus(403);
});

it('rejects an invalid transition, like completing a booking that is not checked in', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $booking = makeBookingIn($organization, BookingStatus::Confirmed);

    $this->postJson("/api/v1/bookings/{$booking->public_id}/complete")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('lets the customer cancel their own booking', function () {
    $organization = Organization::factory()->create();
    $customer = User::factory()->create();
    $booking = makeBookingIn($organization, BookingStatus::Confirmed, $customer);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonStructure(['meta' => ['free_cancellation']]);

    expect($booking->fresh()->cancelled_at)->not->toBeNull();
});

it('forbids a stranger from cancelling someone else\'s booking', function () {
    $organization = Organization::factory()->create();
    $booking = makeBookingIn($organization, BookingStatus::Confirmed);
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/cancel")
        ->assertStatus(403);
});

it('rejects cancelling an already-cancelled booking', function () {
    $organization = Organization::factory()->create();
    $customer = User::factory()->create();
    $booking = makeBookingIn($organization, BookingStatus::Cancelled, $customer);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/cancel")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_ALREADY_CANCELLED');
});

it('reports free_cancellation correctly based on the notice window', function () {
    $organization = Organization::factory()->create(['settings' => ['cancellation_notice_minutes' => 1440]]);
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization);

    $lateBooking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => now()->addHours(2),
        'end_at' => now()->addHours(3),
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$lateBooking->public_id}/cancel")
        ->assertJsonPath('meta.free_cancellation', false);
});
