<?php

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\Role;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Models\User;

it('logs booking.created and booking.confirmed when a booking is created (MVP confirms immediately)', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated();

    $bookingPublicId = $response->json('data.id');

    $actions = AuditLog::where('entity_type', 'Booking')
        ->where('entity_id', $bookingPublicId)
        ->orderBy('id')
        ->pluck('action')
        ->all();

    expect($actions)->toBe(['booking.created', 'booking.confirmed']);

    $createdLog = AuditLog::where('entity_id', $bookingPublicId)->where('action', 'booking.created')->firstOrFail();
    expect($createdLog->actor_id)->toBe($customer->id)
        ->and($createdLog->organization_id)->toBe($organization->id)
        ->and($createdLog->before)->toBeNull();

    $confirmedLog = AuditLog::where('entity_id', $bookingPublicId)->where('action', 'booking.confirmed')->firstOrFail();
    expect($confirmedLog->before)->toBe(['status' => 'pending'])
        ->and($confirmedLog->after)->toBe(['status' => 'confirmed']);
});

it('logs booking.rescheduled with old and new start/end on the same entry', function () {
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
        ->postJson("/api/v1/bookings/{$booking->public_id}/reschedule", [
            'start_at' => $newStart->toIso8601String(),
        ])
        ->assertOk();

    $log = AuditLog::where('entity_type', 'Booking')
        ->where('entity_id', $booking->public_id)
        ->where('action', 'booking.rescheduled')
        ->firstOrFail();

    expect($log->before['start_at'])->toBe($booking->start_at->toIso8601String())
        ->and($log->after['start_at'])->toBe($newStart->toIso8601String());
});

it('logs booking.checked_in, booking.completed, and booking.cancelled for lifecycle transitions', function () {
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

    $this->postJson("/api/v1/bookings/{$booking->public_id}/check-in")->assertOk();
    $this->postJson("/api/v1/bookings/{$booking->public_id}/complete")->assertOk();

    $actions = AuditLog::where('entity_type', 'Booking')
        ->where('entity_id', $booking->public_id)
        ->orderBy('id')
        ->pluck('action')
        ->all();

    expect($actions)->toBe(['booking.checked_in', 'booking.completed']);
});

it('logs booking.cancelled when a booking is cancelled', function () {
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

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/cancel")
        ->assertOk();

    expect(AuditLog::where('entity_type', 'Booking')
        ->where('entity_id', $booking->public_id)
        ->where('action', 'booking.cancelled')
        ->exists())->toBeTrue();
});
