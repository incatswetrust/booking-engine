<?php

use App\Domain\Auth\Role;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingHold;
use App\Domain\Organization\Organization;
use App\Domain\Schedule\ScheduleRule;
use Carbon\CarbonImmutable;

it('keeps a slot available for a capacity > 1 resource when the party still fits', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false, capacity: 20);

    $monday = nextMondayForCapacityTest();
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00']);

    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'start_at' => "{$monday}T09:00:00Z",
        'end_at' => "{$monday}T10:00:00Z",
        'status' => 'confirmed',
        'party_size' => 12,
    ]);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'UTC',
        'party_size' => 8,
    ]))->assertOk();

    expect($response->json('data.0.slots'))->toHaveCount(1);
});

it('excludes a slot for a capacity > 1 resource once the party would not fit', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false, capacity: 20);

    $monday = nextMondayForCapacityTest();
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00']);

    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'start_at' => "{$monday}T09:00:00Z",
        'end_at' => "{$monday}T10:00:00Z",
        'status' => 'confirmed',
        'party_size' => 15,
    ]);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'UTC',
        'party_size' => 8,
    ]))->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('counts a non-expired hold toward capacity in availability results', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false, capacity: 10);

    $monday = nextMondayForCapacityTest();
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00']);

    BookingHold::factory()->create([
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'start_at' => "{$monday}T09:00:00Z",
        'end_at' => "{$monday}T10:00:00Z",
        'expires_at' => now()->addMinutes(10),
        'party_size' => 7,
    ]);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'UTC',
        'party_size' => 5,
    ]))->assertOk();

    expect($response->json('data'))->toBe([]);
});

function nextMondayForCapacityTest(): string
{
    $date = CarbonImmutable::tomorrow('UTC');

    while ($date->dayOfWeek !== CarbonImmutable::MONDAY) {
        $date = $date->addDay();
    }

    return $date->toDateString();
}
