<?php

use App\Domain\Booking\Booking;
use App\Domain\Organization\Organization;
use App\Domain\Schedule\ScheduleRule;
use App\Models\User;
use Carbon\CarbonImmutable;

it('suggests nearby free slots when the requested slot conflicts', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);

    $monday = nextMondayForConflictTest();
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '13:00', 'end_time' => '18:00']);

    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'start_at' => "{$monday}T15:00:00Z",
        'end_at' => "{$monday}T16:00:00Z",
        'status' => 'confirmed',
    ]);

    $customer = User::factory()->create();

    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => "{$monday}T15:00:00Z",
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_SLOT_UNAVAILABLE');

    $alternatives = $response->json('error.details.alternatives');

    expect($alternatives)->not->toBeEmpty()
        ->and($alternatives)->toHaveCount(3)
        ->and(collect($alternatives)->pluck('start'))->not->toContain("{$monday}T15:00:00+00:00");

    // Closest free slots to 15:00 within 13:00-18:00 (minus the 15:00-16:00
    // booking) are 14:00 and 16:00, equally close -- both should appear
    // before slots further away like 13:00 or 17:00.
    expect(collect($alternatives)->pluck('start')->all())
        ->toContain("{$monday}T14:00:00+00:00", "{$monday}T16:00:00+00:00");
});

it('returns no alternatives when the whole day has nothing free', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);

    $monday = nextMondayForConflictTest();
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '15:00', 'end_time' => '16:00']);

    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'start_at' => "{$monday}T15:00:00Z",
        'end_at' => "{$monday}T16:00:00Z",
        'status' => 'confirmed',
    ]);

    $customer = User::factory()->create();

    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => "{$monday}T15:00:00Z",
    ])->assertStatus(409);

    expect($response->json('error.details.alternatives'))->toBe([]);
});

function nextMondayForConflictTest(): string
{
    $date = CarbonImmutable::tomorrow('UTC');

    while ($date->dayOfWeek !== CarbonImmutable::MONDAY) {
        $date = $date->addDay();
    }

    return $date->toDateString();
}
