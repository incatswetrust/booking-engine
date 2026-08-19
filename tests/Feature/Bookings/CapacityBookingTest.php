<?php

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingHold;
use App\Domain\Organization\Organization;
use App\Models\User;

it('lets multiple bookings share a capacity > 1 resource as long as the sum stays within capacity', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, capacity: 20);
    $start = now()->addDay()->setTime(10, 0);

    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
        'status' => 'confirmed',
        'party_size' => 12,
    ]);

    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
        'party_size' => 8,
    ])->assertCreated();

    expect(Booking::where('resource_id', $resource->id)->count())->toBe(2);
});

it('rejects a booking that would push the resource past its capacity', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, capacity: 20);
    $start = now()->addDay()->setTime(10, 0);

    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
        'status' => 'confirmed',
        'party_size' => 15,
    ]);

    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
        'party_size' => 6,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_SLOT_UNAVAILABLE');

    expect(Booking::where('resource_id', $resource->id)->count())->toBe(1);
});

it('counts an active hold\'s party_size toward capacity', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, capacity: 10);
    $start = now()->addDay()->setTime(10, 0);

    BookingHold::factory()->create([
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
        'expires_at' => now()->addMinutes(10),
        'party_size' => 7,
    ]);

    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
        'party_size' => 5,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_SLOT_UNAVAILABLE');
});

it('rejects a booking hold whose party_size exceeds the resource capacity (422, not 409)', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, capacity: 5);
    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/booking-holds', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
        'party_size' => 6,
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('lets a booking created from a hold default its party_size to the hold\'s party_size', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, capacity: 10);
    $customer = User::factory()->create();
    $start = now()->addDay()->setTime(10, 0);

    $holdResponse = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/booking-holds', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
        'party_size' => 4,
    ])->assertCreated();

    $response = $this->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
        'hold_id' => $holdResponse->json('data.id'),
    ])->assertCreated();

    expect($response->json('data.party_size'))->toBe(4);
});

it('rejects a booking whose party_size contradicts the hold it is converting', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, capacity: 10);
    $customer = User::factory()->create();
    $start = now()->addDay()->setTime(10, 0);

    $holdResponse = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/booking-holds', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
        'party_size' => 4,
    ])->assertCreated();

    $this->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
        'hold_id' => $holdResponse->json('data.id'),
        'party_size' => 9,
    ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('rejects a booking whose party_size exceeds the resource capacity (422, not 409)', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, capacity: 5);
    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
        'party_size' => 6,
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('respects capacity when rescheduling into a slot already partly used', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, capacity: 10);
    $customer = User::factory()->create();

    $newStart = now()->addDay()->setTime(14, 0);

    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'start_at' => $newStart,
        'end_at' => $newStart->copy()->addHour(),
        'status' => 'confirmed',
        'party_size' => 7,
    ]);

    $myBooking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'start_at' => now()->addDay()->setTime(9, 0),
        'end_at' => now()->addDay()->setTime(10, 0),
        'status' => 'confirmed',
        'party_size' => 5,
    ]);

    $this->actingAs($customer, 'sanctum')->postJson("/api/v1/bookings/{$myBooking->public_id}/reschedule", [
        'start_at' => $newStart->toIso8601String(),
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_SLOT_UNAVAILABLE');
});
