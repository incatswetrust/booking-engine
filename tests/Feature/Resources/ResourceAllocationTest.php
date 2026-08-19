<?php

use App\Domain\Booking\Booking;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Schedule\ScheduleRule;
use App\Domain\Service\Service;
use App\Models\User;

/**
 * @return array{0: resource, 1: resource, 2: Service}
 */
function makeTwoResourceService(Organization $organization, array $settingsOverride = []): array
{
    $organization->update(['settings' => array_merge($organization->settings, $settingsOverride)]);

    [$resourceA, $service] = makeBookableResource($organization, 60);
    $resourceB = Resource::factory()->for($organization)->for($resourceA->location)->create();
    $service->resources()->attach($resourceB);

    foreach (range(0, 6) as $dayOfWeek) {
        ScheduleRule::factory()->for($resourceB)->create([
            'day_of_week' => $dayOfWeek,
            'start_time' => '00:00',
            'end_time' => '23:59',
        ]);
    }

    return [$resourceA, $resourceB, $service];
}

it('auto-allocates a resource when resource_id is omitted, skipping a busy one', function () {
    $organization = Organization::factory()->create();
    [$resourceA, $resourceB, $service] = makeTwoResourceService($organization);
    $start = now()->addDay()->setTime(10, 0);

    // resourceA (lower id, so "first available" would normally pick it)
    // is busy at this slot -- the allocator should skip it.
    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resourceA->id,
        'service_id' => $service->id,
        'location_id' => $resourceA->location_id,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
        'status' => 'confirmed',
    ]);

    $customer = User::factory()->create();

    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
    ])->assertCreated();

    expect($response->json('data.resource_id'))->toBe($resourceB->public_id);
});

it('returns 409 when no resource is free for auto-allocation', function () {
    $organization = Organization::factory()->create();
    [$resourceA, $resourceB, $service] = makeTwoResourceService($organization);
    $start = now()->addDay()->setTime(10, 0);

    foreach ([$resourceA, $resourceB] as $resource) {
        Booking::factory()->create([
            'organization_id' => $organization->id,
            'resource_id' => $resource->id,
            'service_id' => $service->id,
            'location_id' => $resource->location_id,
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
            'status' => 'confirmed',
        ]);
    }

    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_SLOT_UNAVAILABLE');
});

it('respects the organization\'s least_booked allocation strategy', function () {
    $organization = Organization::factory()->create();
    [$resourceA, $resourceB, $service] = makeTwoResourceService($organization, ['resource_allocation_strategy' => 'least_booked']);

    // resourceA already has 2 active bookings, resourceB has 0 -- at a
    // DIFFERENT time than the one being requested, so both are free for
    // the new slot and least_booked should still prefer resourceB.
    foreach (range(1, 2) as $i) {
        Booking::factory()->create([
            'organization_id' => $organization->id,
            'resource_id' => $resourceA->id,
            'service_id' => $service->id,
            'location_id' => $resourceA->location_id,
            'start_at' => now()->addDays($i)->setTime(8, 0),
            'end_at' => now()->addDays($i)->setTime(9, 0),
            'status' => 'confirmed',
        ]);
    }

    $customer = User::factory()->create();
    $start = now()->addDay()->setTime(15, 0);

    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
    ])->assertCreated();

    expect($response->json('data.resource_id'))->toBe($resourceB->public_id);
});

it('rejects an explicit resource_id together with an auto-allocation-only field mismatch gracefully (still requires resource_id when hold_id is given)', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    $hold = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/booking-holds', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated();

    $this->postJson('/api/v1/bookings', [
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
        'hold_id' => $hold->json('data.id'),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.details.resource_id.0', 'resource_id is required when hold_id is given.');
});
