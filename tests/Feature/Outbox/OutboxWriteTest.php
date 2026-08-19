<?php

use App\Domain\Auth\Role;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Domain\Outbox\OutboxMessage;
use App\Domain\Outbox\OutboxStatus;
use App\Models\User;

it('writes BookingCreated and BookingConfirmed to the outbox when a booking is created', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated();

    $bookingId = $response->json('data.id');

    $messages = OutboxMessage::where('aggregate_type', 'Booking')->where('aggregate_id', $bookingId)->orderBy('id')->get();

    expect($messages->pluck('event_type')->all())->toBe(['BookingCreated', 'BookingConfirmed'])
        ->and($messages->pluck('status')->all())->toEqual([OutboxStatus::Pending, OutboxStatus::Pending]);

    $created = $messages->first();
    expect($created->payload['booking_id'])->toBe($bookingId)
        ->and($created->payload['organization_id'])->toBe($organization->public_id)
        ->and($created->payload['status'])->toBe('pending');

    $confirmed = $messages->last();
    expect($confirmed->payload['status'])->toBe('confirmed');
});

it('does not write an outbox message when booking creation fails and rolls back', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $start = now()->addDay()->setTime(10, 0);

    Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => User::factory()->create()->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
    ]);

    OutboxMessage::query()->delete();
    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => $start->toIso8601String(),
    ])->assertStatus(409);

    expect(OutboxMessage::count())->toBe(0);
});

it('writes BookingRescheduled with old and new start/end in the payload', function () {
    $organization = Organization::factory()->create();
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => now()->addDay()->setTime(10, 0),
        'end_at' => now()->addDay()->setTime(11, 0),
    ]);

    $newStart = now()->addDays(2)->setTime(14, 0);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/reschedule", ['start_at' => $newStart->toIso8601String()])
        ->assertOk();

    $message = OutboxMessage::where('aggregate_id', $booking->public_id)->where('event_type', 'BookingRescheduled')->firstOrFail();

    expect($message->payload['start_at'])->toBe($newStart->toIso8601String())
        ->and($message->payload['old_start_at'])->toBe($booking->start_at->toIso8601String());
});

it('does not write outbox messages for check-in or complete, only for the domain events named in §34', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationManager);
    [$resource, $service] = makeBookableResource($organization, 60);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => User::factory()->create()->id,
        'status' => BookingStatus::Confirmed,
    ]);

    OutboxMessage::query()->delete();

    $this->postJson("/api/v1/bookings/{$booking->public_id}/check-in")->assertOk();
    $this->postJson("/api/v1/bookings/{$booking->public_id}/complete")->assertOk();

    expect(OutboxMessage::count())->toBe(0);
});

it('writes BookingCancelled when a booking is cancelled', function () {
    $organization = Organization::factory()->create();
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => now()->addDays(3),
        'end_at' => now()->addDays(3)->addHour(),
    ]);

    OutboxMessage::query()->delete();

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/cancel")
        ->assertOk();

    $eventTypes = OutboxMessage::where('aggregate_id', $booking->public_id)->pluck('event_type')->all();
    expect($eventTypes)->toBe(['BookingCancelled']);
});
