<?php

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Models\User;

it('reschedules a booking to a free slot', function () {
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
        'start_at' => now()->addDay()->setTime(10, 0),
        'end_at' => now()->addDay()->setTime(11, 0),
    ]);

    $newStart = now()->addDays(2)->setTime(14, 0);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/reschedule", [
            'start_at' => $newStart->toIso8601String(),
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $booking->public_id);

    $booking->refresh();
    expect($booking->start_at->equalTo($newStart))->toBeTrue();
});

it('rejects rescheduling onto a slot that is already booked', function () {
    $organization = Organization::factory()->create();
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);

    $blockingStart = now()->addDays(2)->setTime(14, 0);
    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'status' => BookingStatus::Confirmed,
        'start_at' => $blockingStart,
        'end_at' => $blockingStart->copy()->addHour(),
    ]);

    $originalStart = now()->addDay()->setTime(10, 0);
    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => $originalStart,
        'end_at' => $originalStart->copy()->addHour(),
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/reschedule", [
            'start_at' => $blockingStart->toIso8601String(),
        ])
        ->assertStatus(409);

    // The original slot must still be intact — reschedule is atomic (§27).
    $booking->refresh();
    expect($booking->start_at->equalTo($originalStart))->toBeTrue();
});

it('allows rescheduling a booking onto its own current slot', function () {
    $organization = Organization::factory()->create();
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $start = now()->addDay()->setTime(10, 0);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/reschedule", [
            'start_at' => $start->toIso8601String(),
        ])
        ->assertOk();
});

it('rejects rescheduling a cancelled booking', function () {
    $organization = Organization::factory()->create();
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Cancelled,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/reschedule", [
            'start_at' => now()->addDays(3)->toIso8601String(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'BOOKING_CANNOT_BE_RESCHEDULED');
});

it('forbids a stranger from rescheduling someone else\'s booking', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'status' => BookingStatus::Confirmed,
    ]);

    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/reschedule", [
            'start_at' => now()->addDays(3)->toIso8601String(),
        ])
        ->assertStatus(403);
});
