<?php

use App\Domain\Booking\BookingHold;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use App\Models\User;

function makeBookableResource(Organization $organization, int $durationMinutes = 60): array
{
    $location = Location::factory()->for($organization)->create();
    $resource = Resource::factory()->for($organization)->for($location)->create();
    $service = Service::factory()->for($organization)->create(['duration_minutes' => $durationMinutes]);
    $service->resources()->attach($resource);

    return [$resource, $service];
}

it('creates a hold for a free slot', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization);
    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/booking-holds', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.resource_id', $resource->public_id)
        ->assertJsonPath('data.service_id', $service->public_id);
});

it('rejects a hold that overlaps an existing active hold on the same resource', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $start = now()->addDay()->setTime(10, 0);

    BookingHold::factory()->create([
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/booking-holds', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $start->copy()->addMinutes(30)->toIso8601String(),
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_SLOT_UNAVAILABLE');
});

it('allows holding a different resource at the exact same time', function () {
    $organization = Organization::factory()->create();
    [$resourceA, $serviceA] = makeBookableResource($organization);
    $location = Location::factory()->for($organization)->create();
    $resourceB = Resource::factory()->for($organization)->for($location)->create();
    $serviceA->resources()->attach($resourceB);

    $start = now()->addDay()->setTime(10, 0);

    BookingHold::factory()->create([
        'resource_id' => $resourceA->id,
        'service_id' => $serviceA->id,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/booking-holds', [
        'resource_id' => $resourceB->public_id,
        'service_id' => $serviceA->public_id,
        'start_at' => $start->toIso8601String(),
    ])->assertCreated();
});

it('does not conflict with an already-expired hold', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization);
    $start = now()->addDay()->setTime(10, 0);

    BookingHold::factory()->create([
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
        'expires_at' => now()->subMinute(),
    ]);

    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/booking-holds', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
    ])->assertCreated();
});

it('rejects a service that is not offered on the given resource', function () {
    $organization = Organization::factory()->create();
    [$resource] = makeBookableResource($organization);
    $unrelatedService = Service::factory()->for($organization)->create();
    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/booking-holds', [
        'resource_id' => $resource->public_id,
        'service_id' => $unrelatedService->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.details.service_id.0', 'This service is not offered on the given resource.');
});

it('lets the holder release their own hold', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization);
    $customer = User::factory()->create();
    $hold = BookingHold::factory()->create([
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->deleteJson("/api/v1/booking-holds/{$hold->public_id}")
        ->assertNoContent();

    expect(BookingHold::find($hold->id))->toBeNull();
});

it('forbids releasing someone else\'s hold', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization);
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $hold = BookingHold::factory()->create([
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'customer_id' => $owner->id,
    ]);

    $this->actingAs($stranger, 'sanctum')
        ->deleteJson("/api/v1/booking-holds/{$hold->public_id}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');
});

it('cleans up only expired holds via the scheduled command', function () {
    BookingHold::factory()->create(['expires_at' => now()->subHour()]);
    BookingHold::factory()->create(['expires_at' => now()->addHour()]);

    $this->artisan('bookings:expire-holds')->assertExitCode(0);

    expect(BookingHold::count())->toBe(1);
});
