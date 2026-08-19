<?php

use App\Domain\Booking\Booking;
use App\Domain\Calendar\CalendarConnection;
use App\Domain\Organization\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Full pipeline, same shape as WebhookDeliveryDispatchTest: booking
 * action -> outbox write -> outbox:relay -> ProcessOutboxEvent fires
 * the domain event -> the Sync*ToCalendar listener (queued on
 * "calendar") dispatches SyncBookingToCalendar -- which, under the
 * "sync" queue driver used in tests, runs immediately and makes a real
 * (Http::fake()'d) call to Google.
 */
it('creates a calendar event when a booking is confirmed for a resource with a connected calendar', function () {
    Http::fake(['https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt_dispatch'], 200)]);

    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    CalendarConnection::factory()->for($resource)->create(['access_token' => 'token', 'external_calendar_id' => 'primary']);

    $customer = User::factory()->create();

    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated();

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    $booking = Booking::where('public_id', $response->json('data.id'))->firstOrFail();
    expect($booking->external_calendar_event_id)->toBe('evt_dispatch');
});

it('does not dispatch a calendar sync for a resource with no connected calendar', function () {
    Http::fake();

    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated();

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    Http::assertNothingSent();
});

it('deletes the calendar event when a booking is cancelled', function () {
    Http::fake(['https://www.googleapis.com/calendar/v3/calendars/primary/events/evt_to_cancel' => Http::response('', 200)]);

    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    CalendarConnection::factory()->for($resource)->create(['access_token' => 'token', 'external_calendar_id' => 'primary']);

    $customer = User::factory()->create();
    $booking = Booking::factory()->for($resource)->create([
        'organization_id' => $organization->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'external_calendar_event_id' => 'evt_to_cancel',
    ]);

    $this->actingAs($customer, 'sanctum')->postJson("/api/v1/bookings/{$booking->public_id}/cancel")
        ->assertOk();

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    expect($booking->refresh()->external_calendar_event_id)->toBeNull();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});
