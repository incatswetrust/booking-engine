<?php

use App\Domain\Booking\Booking;
use App\Domain\Organization\Organization;
use App\Models\User;

it('creates a booking for a brand-new guest, identified by email', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);

    $response = $this->postJson('/api/v1/public/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
        'customer_name' => 'Jane Guest',
        'customer_email' => 'jane.guest@example.com',
    ])->assertCreated();

    $booking = Booking::firstOrFail();
    expect($response->json('data.customer_id'))->toBe($booking->customer->public_id);

    $customer = User::where('email', 'jane.guest@example.com')->firstOrFail();
    expect($customer->name)->toBe('Jane Guest')
        ->and($booking->customer_id)->toBe($customer->id);
});

it('reuses an existing user when the email matches, without logging in', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $existing = User::factory()->create(['email' => 'returning@example.com', 'name' => 'Returning Customer']);

    $this->postJson('/api/v1/public/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
        'customer_name' => 'Someone Else Typed This',
        'customer_email' => 'returning@example.com',
    ])->assertCreated();

    expect(User::where('email', 'returning@example.com')->count())->toBe(1);

    $booking = Booking::firstOrFail();
    expect($booking->customer_id)->toBe($existing->id);
});

it('auto-allocates a resource on the public endpoint when resource_id is omitted', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);

    $response = $this->postJson('/api/v1/public/bookings', [
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
        'customer_name' => 'Jane Guest',
        'customer_email' => 'jane.guest@example.com',
    ])->assertCreated();

    expect($response->json('data.resource_id'))->toBe($resource->public_id);
});

it('rejects a conflicting public booking with 409', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $start = now()->addDay()->setTime(10, 0);

    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
        'status' => 'confirmed',
    ]);

    $this->postJson('/api/v1/public/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
        'customer_name' => 'Jane Guest',
        'customer_email' => 'jane.guest@example.com',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_SLOT_UNAVAILABLE');
});

it('requires customer_name and customer_email', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);

    $this->postJson('/api/v1/public/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('exposes availability without authentication', function () {
    $organization = Organization::factory()->create();
    [, $service] = makeBookableResource($organization, 60);

    $this->getJson('/api/v1/public/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'date_from' => now()->addDay()->toDateString(),
        'date_to' => now()->addDay()->toDateString(),
        'timezone' => 'UTC',
    ]))->assertOk();
});

it('rate-limits public bookings much harder than authenticated ones', function () {
    $organization = Organization::factory()->create();
    [, $service] = makeBookableResource($organization, 60);

    $response = null;

    for ($i = 0; $i < 25; $i++) {
        $response = $this->getJson('/api/v1/public/availability?'.http_build_query([
            'service_id' => $service->public_id,
            'date_from' => now()->addDay()->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
            'timezone' => 'UTC',
        ]));
    }

    expect($response->status())->toBe(429);
});
