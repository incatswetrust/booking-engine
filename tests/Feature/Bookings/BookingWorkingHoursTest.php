<?php

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Domain\Schedule\ScheduleException;
use App\Domain\Schedule\ScheduleRule;
use App\Models\User;

/**
 * BookingService/BookingHoldService previously didn't validate against
 * schedule_rules/schedule_exceptions at all — a booking could be created
 * or held entirely outside a resource's working hours by calling
 * /bookings or /booking-holds directly instead of picking a slot from
 * /availability first (§17). These tests cover that gap directly, using
 * a resource with no default open schedule (makeBookableResource(...,
 * withOpenSchedule: false)) so the working-hours check is the only thing
 * under test.
 */
it('rejects a booking for a resource with no schedule at all', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);
    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->setTime(10, 0)->toIso8601String(),
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_SLOT_UNAVAILABLE');
});

it('rejects a booking that falls outside the scheduled interval for that day', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);
    $customer = User::factory()->create();

    $tomorrow = now()->addDay();
    ScheduleRule::factory()->for($resource)->create([
        'day_of_week' => $tomorrow->dayOfWeek,
        'start_time' => '09:00',
        'end_time' => '10:00',
    ]);

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $tomorrow->copy()->setTime(20, 0)->toIso8601String(),
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_SLOT_UNAVAILABLE');
});

it('creates a booking that falls within a narrow scheduled interval', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);
    $customer = User::factory()->create();

    $tomorrow = now()->addDay();
    ScheduleRule::factory()->for($resource)->create([
        'day_of_week' => $tomorrow->dayOfWeek,
        'start_time' => '09:00',
        'end_time' => '10:00',
    ]);

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $tomorrow->copy()->setTime(9, 0)->toIso8601String(),
    ])->assertCreated();
});

it('rejects a booking on a day covered by a closed schedule exception, even though the weekly rule is open', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    $tomorrow = now()->addDay();
    ScheduleException::factory()->for($resource)->create([
        'date' => $tomorrow->toDateString(),
        'type' => 'closed',
        'start_time' => null,
        'end_time' => null,
    ]);

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $tomorrow->copy()->setTime(10, 0)->toIso8601String(),
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_SLOT_UNAVAILABLE');
});

it('rejects rescheduling a booking to a time outside working hours', function () {
    $organization = Organization::factory()->create();
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);

    $tomorrow = now()->addDay();
    ScheduleRule::factory()->for($resource)->create([
        'day_of_week' => $tomorrow->dayOfWeek,
        'start_time' => '09:00',
        'end_time' => '10:00',
    ]);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => $tomorrow->copy()->setTime(9, 0),
        'end_at' => $tomorrow->copy()->setTime(10, 0),
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/reschedule", [
            'start_at' => $tomorrow->copy()->setTime(20, 0)->toIso8601String(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_SLOT_UNAVAILABLE');
});

it('rejects a booking hold for a resource with no schedule at all', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);
    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/booking-holds', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->setTime(10, 0)->toIso8601String(),
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_SLOT_UNAVAILABLE');
});
