<?php

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Waitlist\WaitlistEntry;
use App\Domain\Waitlist\WaitlistStatus;
use App\Models\User;
use App\Notifications\WaitlistAvailableNotification;
use Illuminate\Support\Facades\Notification;

it('notifies a waitlisted customer for the exact resource and slot when it is cancelled', function () {
    Notification::fake();

    $organization = Organization::factory()->create();
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $start = now()->addDay()->setTime(10, 0);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
    ]);

    $waiter = User::factory()->create();
    $entry = WaitlistEntry::factory()->create([
        'customer_id' => $waiter->id,
        'service_id' => $service->id,
        'resource_id' => $resource->id,
        'desired_start_at' => $start,
        'status' => WaitlistStatus::Waiting,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/cancel")
        ->assertOk();

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    Notification::assertSentTo($waiter, WaitlistAvailableNotification::class);
    expect($entry->refresh()->status)->toBe(WaitlistStatus::Notified);
});

it('notifies a waitlist entry with no specific resource when any matching resource frees up', function () {
    Notification::fake();

    $organization = Organization::factory()->create();
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $start = now()->addDay()->setTime(10, 0);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
    ]);

    $waiter = User::factory()->create();
    WaitlistEntry::factory()->create([
        'customer_id' => $waiter->id,
        'service_id' => $service->id,
        'resource_id' => null,
        'desired_start_at' => $start,
        'status' => WaitlistStatus::Waiting,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/cancel")
        ->assertOk();

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    Notification::assertSentTo($waiter, WaitlistAvailableNotification::class);
});

it('does not notify an entry waiting for a different time or a different specific resource', function () {
    Notification::fake();

    $organization = Organization::factory()->create();
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $otherResource = Resource::factory()->for($organization)->for($resource->location)->create();
    $start = now()->addDay()->setTime(10, 0);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => $start,
        'end_at' => $start->copy()->addHour(),
    ]);

    $wrongTime = User::factory()->create();
    WaitlistEntry::factory()->create([
        'customer_id' => $wrongTime->id,
        'service_id' => $service->id,
        'resource_id' => null,
        'desired_start_at' => $start->copy()->addHour(),
        'status' => WaitlistStatus::Waiting,
    ]);

    $wrongResource = User::factory()->create();
    WaitlistEntry::factory()->create([
        'customer_id' => $wrongResource->id,
        'service_id' => $service->id,
        'resource_id' => $otherResource->id,
        'desired_start_at' => $start,
        'status' => WaitlistStatus::Waiting,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/bookings/{$booking->public_id}/cancel")
        ->assertOk();

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    Notification::assertNotSentTo($wrongTime, WaitlistAvailableNotification::class);
    Notification::assertNotSentTo($wrongResource, WaitlistAvailableNotification::class);
});
