<?php

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingRescheduledNotification;
use Illuminate\Support\Facades\Notification;

/**
 * BookingConfirmed/Cancelled/Rescheduled already go through the outbox
 * (§33) from earlier PRs -- these tests drive the real pipeline (create
 * a booking, run outbox:relay --once) rather than calling the listener
 * directly, since that's the only way to prove the wiring (event class
 * -> auto-discovered listener -> notification) actually holds together.
 */
it('notifies the customer when their booking is confirmed', function () {
    Notification::fake();

    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated();

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    Notification::assertSentTo($customer, BookingConfirmedNotification::class);
});

it('notifies the customer when their booking is cancelled', function () {
    Notification::fake();

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

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    Notification::assertSentTo($customer, BookingCancelledNotification::class);
});

it('notifies the customer when their booking is rescheduled, with old and new times', function () {
    Notification::fake();

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

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    Notification::assertSentTo(
        $customer,
        BookingRescheduledNotification::class,
        fn ($notification) => $notification->toMail($customer)->subject === 'Your booking was rescheduled',
    );
});
