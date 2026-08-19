<?php

use App\Application\Services\PricingEngine;
use App\Domain\Auth\Role;
use App\Domain\Booking\Booking;
use App\Domain\Organization\Organization;
use App\Domain\Service\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

function nextWeekdayForPricingTest(): CarbonImmutable
{
    $date = CarbonImmutable::tomorrow('UTC');

    while ($date->isWeekend()) {
        $date = $date->addDay();
    }

    return $date;
}

function nextWeekendForPricingTest(): CarbonImmutable
{
    $date = CarbonImmutable::tomorrow('UTC');

    while (! $date->isWeekend()) {
        $date = $date->addDay();
    }

    return $date;
}

it('returns the flat service price when there are no pricing_rules', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['price' => 42.50, 'pricing_rules' => null]);

    $start = nextWeekdayForPricingTest()->setTime(10, 0);

    $price = app(PricingEngine::class)->calculate($service, $resource, $start, $start->addHour());

    expect($price)->toBe('42.50');
});

it('uses the weekend_price override on a weekend', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['price' => 40, 'pricing_rules' => ['weekend_price' => 55]]);

    $weekend = nextWeekendForPricingTest()->setTime(10, 0);

    $price = app(PricingEngine::class)->calculate($service, $resource, $weekend, $weekend->addHour());

    expect($price)->toBe('55.00');
});

it('keeps the base price on a weekday even when weekend_price is set', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['price' => 40, 'pricing_rules' => ['weekend_price' => 55]]);

    $weekday = nextWeekdayForPricingTest()->setTime(10, 0);

    $price = app(PricingEngine::class)->calculate($service, $resource, $weekday, $weekday->addHour());

    expect($price)->toBe('40.00');
});

it('applies a time-of-day multiplier when the booking starts inside the window', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update([
        'price' => 100,
        'pricing_rules' => ['time_of_day_multipliers' => [
            ['start' => '18:00', 'end' => '22:00', 'multiplier' => 1.20],
        ]],
    ]);

    $weekday = nextWeekdayForPricingTest()->setTime(19, 0);

    $price = app(PricingEngine::class)->calculate($service, $resource, $weekday, $weekday->addHour());

    expect($price)->toBe('120.00');
});

it('does not apply a time-of-day multiplier outside the window', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update([
        'price' => 100,
        'pricing_rules' => ['time_of_day_multipliers' => [
            ['start' => '18:00', 'end' => '22:00', 'multiplier' => 1.20],
        ]],
    ]);

    $weekday = nextWeekdayForPricingTest()->setTime(10, 0);

    $price = app(PricingEngine::class)->calculate($service, $resource, $weekday, $weekday->addHour());

    expect($price)->toBe('100.00');
});

it('applies an occupancy surcharge once booked capacity exceeds the threshold', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, capacity: 10);
    $service->update([
        'price' => 100,
        'pricing_rules' => ['occupancy_surcharge' => ['threshold_percent' => 80, 'multiplier' => 1.15]],
    ]);

    $start = nextWeekdayForPricingTest()->setTime(10, 0);

    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
        'status' => 'confirmed',
        'party_size' => 9,
    ]);

    $price = app(PricingEngine::class)->calculate($service, $resource, $start, $start->addHour());

    expect($price)->toBe('115.00');
});

it('does not apply an occupancy surcharge below the threshold', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, capacity: 10);
    $service->update([
        'price' => 100,
        'pricing_rules' => ['occupancy_surcharge' => ['threshold_percent' => 80, 'multiplier' => 1.15]],
    ]);

    $start = nextWeekdayForPricingTest()->setTime(10, 0);

    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
        'status' => 'confirmed',
        'party_size' => 5,
    ]);

    $price = app(PricingEngine::class)->calculate($service, $resource, $start, $start->addHour());

    expect($price)->toBe('100.00');
});

it('compounds weekend, time-of-day and occupancy rules together', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60, capacity: 10);
    $service->update([
        'price' => 40,
        'pricing_rules' => [
            'weekend_price' => 55,
            'time_of_day_multipliers' => [['start' => '18:00', 'end' => '22:00', 'multiplier' => 1.20]],
            'occupancy_surcharge' => ['threshold_percent' => 80, 'multiplier' => 1.15],
        ],
    ]);

    $weekend = nextWeekendForPricingTest()->setTime(19, 0);

    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'start_at' => $weekend,
        'end_at' => $weekend->copy()->addHour(),
        'status' => 'confirmed',
        'party_size' => 9,
    ]);

    $price = app(PricingEngine::class)->calculate($service, $resource, $weekend, $weekend->addHour());

    // 55 (weekend) * 1.20 (time-of-day) * 1.15 (occupancy) = 75.90
    expect($price)->toBe('75.90');
});

it('sets a booking\'s price from the pricing engine at creation time', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['price' => 40, 'pricing_rules' => ['weekend_price' => 55]]);

    $customer = User::factory()->create();
    $weekend = nextWeekendForPricingTest()->setTime(10, 0);

    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $weekend->toIso8601String(),
    ])->assertCreated();

    expect((float) $response->json('data.price'))->toBe(55.0);
});

it('does not recalculate price on reschedule, even into a differently-priced slot', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['price' => 40, 'pricing_rules' => ['weekend_price' => 55]]);

    $customer = User::factory()->create();
    $weekday = nextWeekdayForPricingTest()->setTime(10, 0);

    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $weekday->toIso8601String(),
    ])->assertCreated();

    expect((float) $response->json('data.price'))->toBe(40.0);

    $bookingId = $response->json('data.id');
    $weekend = nextWeekendForPricingTest()->setTime(10, 0);

    $rescheduled = $this->postJson("/api/v1/bookings/{$bookingId}/reschedule", [
        'start_at' => $weekend->toIso8601String(),
    ])->assertOk();

    expect((float) $rescheduled->json('data.price'))->toBe(40.0);
});

it('accepts pricing_rules when creating a service', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $response = $this->postJson('/api/v1/services', [
        'organization_id' => $organization->public_id,
        'name' => 'Yoga Class',
        'duration_minutes' => 60,
        'price' => 40,
        'currency' => 'EUR',
        'pricing_rules' => [
            'weekend_price' => 55,
            'time_of_day_multipliers' => [['start' => '18:00', 'end' => '22:00', 'multiplier' => 1.2]],
            'occupancy_surcharge' => ['threshold_percent' => 80, 'multiplier' => 1.15],
        ],
    ])->assertCreated();

    expect($response->json('data.pricing_rules.weekend_price'))->toBe(55);

    $service = Service::firstOrFail();
    expect($service->pricing_rules['occupancy_surcharge']['multiplier'])->toBe(1.15);
});

it('rejects a malformed time_of_day_multipliers entry', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $this->postJson('/api/v1/services', [
        'organization_id' => $organization->public_id,
        'name' => 'Yoga Class',
        'duration_minutes' => 60,
        'price' => 40,
        'currency' => 'EUR',
        'pricing_rules' => [
            'time_of_day_multipliers' => [['start' => 'not-a-time', 'end' => '22:00', 'multiplier' => 1.2]],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('lets an owner update a service\'s pricing_rules', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $service = Service::factory()->for($organization)->create(['price' => 40]);

    $this->patchJson("/api/v1/services/{$service->public_id}", [
        'pricing_rules' => ['weekend_price' => 55],
    ])
        ->assertOk()
        ->assertJsonPath('data.pricing_rules.weekend_price', 55);
});
