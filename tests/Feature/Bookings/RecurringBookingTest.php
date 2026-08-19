<?php

use App\Domain\Booking\Booking;
use App\Domain\Organization\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;

it('creates every occurrence with all_or_nothing when the whole series is free', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    $firstStart = nextTuesdayForRecurringTest()->setTime(18, 0);

    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/recurring-bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'first_start_at' => $firstStart->toIso8601String(),
        'occurrences' => 8,
        'strategy' => 'all_or_nothing',
    ])->assertCreated();

    expect($response->json('data.bookings'))->toHaveCount(8)
        ->and($response->json('data.skipped'))->toBe([])
        ->and($response->json('data.strategy'))->toBe('all_or_nothing');

    $recurringId = $response->json('data.recurring_booking_id');
    expect($recurringId)->not->toBeNull();

    $bookings = Booking::where('recurring_booking_id', $recurringId)->orderBy('start_at')->get();
    expect($bookings)->toHaveCount(8);

    foreach ($bookings as $i => $booking) {
        expect($booking->start_at->equalTo($firstStart->copy()->addWeeks($i)))->toBeTrue();
    }
});

it('creates nothing with all_or_nothing when one occurrence conflicts', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    $firstStart = nextTuesdayForRecurringTest()->setTime(18, 0);
    $conflictingStart = $firstStart->copy()->addWeeks(3);

    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'start_at' => $conflictingStart,
        'end_at' => $conflictingStart->copy()->addHour(),
        'status' => 'confirmed',
    ]);

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/recurring-bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'first_start_at' => $firstStart->toIso8601String(),
        'occurrences' => 8,
        'strategy' => 'all_or_nothing',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_SLOT_UNAVAILABLE');

    expect(Booking::where('resource_id', $resource->id)->count())->toBe(1);
});

it('creates the available occurrences and reports skipped ones with book_available', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    $firstStart = nextTuesdayForRecurringTest()->setTime(18, 0);
    $conflictingStart = $firstStart->copy()->addWeeks(3);

    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'start_at' => $conflictingStart,
        'end_at' => $conflictingStart->copy()->addHour(),
        'status' => 'confirmed',
    ]);

    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/recurring-bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'first_start_at' => $firstStart->toIso8601String(),
        'occurrences' => 5,
        'strategy' => 'book_available',
    ])->assertCreated();

    expect($response->json('data.bookings'))->toHaveCount(4)
        ->and($response->json('data.skipped'))->toHaveCount(1)
        ->and($response->json('data.skipped.0.start_at'))->toBe($conflictingStart->toIso8601String());

    // 4 created by this request + the 1 pre-existing conflicting booking.
    expect(Booking::where('resource_id', $resource->id)->count())->toBe(5);
});

it('rejects a recurring booking whose party_size exceeds resource capacity', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, capacity: 5);
    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/recurring-bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'first_start_at' => nextTuesdayForRecurringTest()->setTime(18, 0)->toIso8601String(),
        'occurrences' => 2,
        'party_size' => 6,
        'strategy' => 'all_or_nothing',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('forbids a plain customer from booking a recurring series on behalf of someone else', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/recurring-bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'first_start_at' => nextTuesdayForRecurringTest()->setTime(18, 0)->toIso8601String(),
        'occurrences' => 2,
        'strategy' => 'all_or_nothing',
        'customer_id' => $other->public_id,
    ])->assertStatus(403);
});

function nextTuesdayForRecurringTest(): CarbonImmutable
{
    $date = CarbonImmutable::tomorrow('UTC');

    while ($date->dayOfWeek !== CarbonImmutable::TUESDAY) {
        $date = $date->addDay();
    }

    return $date;
}
