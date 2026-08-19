<?php

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingReminderSent;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Models\User;
use App\Notifications\BookingReminderNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;

function makeUpcomingConfirmedBooking(Organization $organization, CarbonInterface $startAt): array
{
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
        'status' => BookingStatus::Confirmed,
        'start_at' => $startAt,
        'end_at' => $startAt->copy()->addHour(),
    ]);

    return [$booking, $customer];
}

it('sends a reminder once a booking enters its offset window', function () {
    Notification::fake();

    $organization = Organization::factory()->create(['settings' => ['reminder_offsets_minutes' => [15]]]);
    [$booking, $customer] = makeUpcomingConfirmedBooking($organization, now()->addMinutes(10));

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertSentTo($customer, BookingReminderNotification::class);
    expect(BookingReminderSent::where('booking_id', $booking->id)->pluck('offset_minutes')->all())->toBe([15]);
});

it('does not send a reminder for an offset the booking has not reached yet', function () {
    Notification::fake();

    $organization = Organization::factory()->create(['settings' => ['reminder_offsets_minutes' => [15]]]);
    [, $customer] = makeUpcomingConfirmedBooking($organization, now()->addHours(3));

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertNotSentTo($customer, BookingReminderNotification::class);
});

it('does not resend a reminder for an offset already recorded as sent', function () {
    Notification::fake();

    $organization = Organization::factory()->create(['settings' => ['reminder_offsets_minutes' => [15]]]);
    [$booking, $customer] = makeUpcomingConfirmedBooking($organization, now()->addMinutes(10));

    BookingReminderSent::create(['booking_id' => $booking->id, 'offset_minutes' => 15, 'sent_at' => now()]);

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertNotSentTo($customer, BookingReminderNotification::class);
});

it('sends every offset already crossed by the time the booking is first evaluated', function () {
    Notification::fake();

    // Nested offsets: being 10 minutes out means the 120min and 1440min
    // windows were also already entered, not just the 15min one -- all
    // three are legitimately "due" the first time this booking is seen.
    $organization = Organization::factory()->create(['settings' => ['reminder_offsets_minutes' => [1440, 120, 15]]]);
    [$booking] = makeUpcomingConfirmedBooking($organization, now()->addMinutes(10));

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    expect(BookingReminderSent::where('booking_id', $booking->id)->pluck('offset_minutes')->sort()->values()->all())
        ->toBe([15, 120, 1440]);
});
