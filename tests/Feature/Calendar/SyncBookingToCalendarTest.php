<?php

use App\Domain\Booking\Booking;
use App\Domain\Calendar\CalendarConnection;
use App\Domain\Calendar\CalendarConnectionStatus;
use App\Domain\Calendar\CalendarProviderResolver;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Jobs\SyncBookingToCalendar;
use Illuminate\Support\Facades\Http;

function bookingWithCalendarConnection(): Booking
{
    $organization = Organization::factory()->create();
    $resource = Resource::factory()->for($organization)->create();
    CalendarConnection::factory()->for($resource)->create(['access_token' => 'token', 'external_calendar_id' => 'primary']);

    return Booking::factory()->for($resource)->create([
        'organization_id' => $organization->id,
        'location_id' => $resource->location_id,
    ]);
}

it('creates a calendar event for a booking with no existing event id', function () {
    Http::fake(['https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt_new'], 200)]);

    $booking = bookingWithCalendarConnection();

    (new SyncBookingToCalendar($booking->public_id, 'sync'))->handle(app(CalendarProviderResolver::class));

    expect($booking->refresh()->external_calendar_event_id)->toBe('evt_new');
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/events'));
});

it('updates the existing calendar event when one is already linked', function () {
    Http::fake(['https://www.googleapis.com/calendar/v3/calendars/primary/events/evt_existing' => Http::response(['id' => 'evt_existing'], 200)]);

    $booking = bookingWithCalendarConnection();
    $booking->forceFill(['external_calendar_event_id' => 'evt_existing'])->save();

    (new SyncBookingToCalendar($booking->public_id, 'sync'))->handle(app(CalendarProviderResolver::class));

    Http::assertSent(fn ($request) => $request->method() === 'PUT');
});

it('deletes the linked calendar event and clears external_calendar_event_id', function () {
    Http::fake(['https://www.googleapis.com/calendar/v3/calendars/primary/events/evt_existing' => Http::response('', 200)]);

    $booking = bookingWithCalendarConnection();
    $booking->forceFill(['external_calendar_event_id' => 'evt_existing'])->save();

    (new SyncBookingToCalendar($booking->public_id, 'delete'))->handle(app(CalendarProviderResolver::class));

    expect($booking->refresh()->external_calendar_event_id)->toBeNull();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});

it('does nothing for a resource with no active calendar connection', function () {
    Http::fake();

    $organization = Organization::factory()->create();
    $resource = Resource::factory()->for($organization)->create();
    $booking = Booking::factory()->for($resource)->create([
        'organization_id' => $organization->id,
        'location_id' => $resource->location_id,
    ]);

    (new SyncBookingToCalendar($booking->public_id, 'sync'))->handle(app(CalendarProviderResolver::class));

    Http::assertNothingSent();
});

it('does nothing for a disabled calendar connection', function () {
    Http::fake();

    $organization = Organization::factory()->create();
    $resource = Resource::factory()->for($organization)->create();
    CalendarConnection::factory()->for($resource)->create(['status' => CalendarConnectionStatus::Disabled]);
    $booking = Booking::factory()->for($resource)->create([
        'organization_id' => $organization->id,
        'location_id' => $resource->location_id,
    ]);

    (new SyncBookingToCalendar($booking->public_id, 'sync'))->handle(app(CalendarProviderResolver::class));

    Http::assertNothingSent();
});
