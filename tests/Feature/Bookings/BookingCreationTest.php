<?php

use App\Domain\Auth\Role;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingHold;
use App\Domain\Organization\Organization;
use App\Domain\Resource\ResourceBlock;
use App\Domain\Service\Service;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('creates a booking directly and confirms it immediately (no payment in MVP)', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.customer_id', $customer->public_id)
        ->assertJsonPath('data.price', (float) $service->price);

    $booking = Booking::first();
    expect($booking->statusHistory()->count())->toBe(2);
    expect($booking->statusHistory()->pluck('to_status')->all())->toBe(['pending', 'confirmed']);
});

it('stores a non-UTC start_at converted to UTC, not with the offset dropped (§18)', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    // 09:00+03:00 is 06:00 UTC. If the +03:00 offset gets silently
    // dropped instead of converted, this would wrongly persist as
    // 09:00 UTC.
    $localStart = now()->addDay()->setTime(9, 0)->setTimezone('Europe/Bucharest');

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $localStart->toIso8601String(),
    ])->assertCreated();

    $rawStartAt = DB::table('bookings')->value('start_at');

    expect(Carbon::parse($rawStartAt, 'UTC')->equalTo($localStart))->toBeTrue();
});

it('rejects a booking that overlaps an existing active booking', function () {
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

    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $start->copy()->addMinutes(30)->toIso8601String(),
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_SLOT_UNAVAILABLE');
});

it('rejects a booking over a slot someone else is actively holding', function () {
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

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
    ])->assertStatus(409);
});

it('rejects a booking that falls inside a resource block', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $start = now()->addDay()->setTime(10, 0);

    ResourceBlock::factory()->create([
        'resource_id' => $resource->id,
        'starts_at' => $start->copy()->subHour(),
        'ends_at' => $start->copy()->addHours(2),
        'reason' => 'maintenance',
    ]);

    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
    ])->assertStatus(409);
});

it('consumes the hold when creating a booking from it', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();
    $start = now()->addDay()->setTime(10, 0);

    $hold = BookingHold::factory()->create([
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
        'hold_id' => $hold->public_id,
    ])->assertCreated();

    expect(BookingHold::find($hold->id))->toBeNull();
});

it('lets staff with bookings.create create a booking for another customer', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization);
    $owner = actingAsMember($this, $organization, Role::OrganizationOwner);
    $walkIn = User::factory()->create();

    $this->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
        'customer_id' => $walkIn->public_id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.customer_id', $walkIn->public_id);
});

it('forbids a plain customer from booking on behalf of someone else', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization);
    $customer = User::factory()->create();
    $someoneElse = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
        'customer_id' => $someoneElse->public_id,
    ])->assertStatus(403);
});

it('rejects a service that is not offered on the given resource', function () {
    $organization = Organization::factory()->create();
    [$resource] = makeBookableResource($organization);
    $unrelatedService = Service::factory()->for($organization)->create();
    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $unrelatedService->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
    ])->assertStatus(422);
});
