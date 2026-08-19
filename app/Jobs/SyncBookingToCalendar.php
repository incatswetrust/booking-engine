<?php

namespace App\Jobs;

use App\Domain\Booking\Booking;
use App\Domain\Calendar\CalendarConnectionStatus;
use App\Domain\Calendar\CalendarProviderResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * §36/§61: one job handles create/update/delete alike -- whether a
 * booking's calendar event needs creating or updating collapses to the
 * same "upsert" case (external_calendar_event_id null vs set), so
 * "sync" only branches on that vs. an explicit delete. Re-dispatched
 * per booking mutation from SyncsBookingToCalendar's concrete
 * listeners, same split (listener decides IF a sync is needed, this
 * job does the actual provider call with its own retry budget) as
 * DispatchesWebhookDeliveries/DeliverWebhook.
 */
class SyncBookingToCalendar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public string $queue = 'calendar';

    public int $tries = 5;

    public function __construct(
        public readonly string $bookingPublicId,
        public readonly string $action,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 43200];
    }

    public function handle(CalendarProviderResolver $providers): void
    {
        $booking = Booking::where('public_id', $this->bookingPublicId)
            ->with(['resource.calendarConnection', 'resource.location', 'resource.organization', 'service', 'customer'])
            ->first();

        if ($booking === null) {
            return;
        }

        $connection = $booking->resource->calendarConnection;

        if ($connection === null || $connection->status !== CalendarConnectionStatus::Active) {
            return;
        }

        $provider = $providers->resolve($connection->provider);

        if ($this->action === 'delete') {
            if ($booking->external_calendar_event_id !== null) {
                $provider->deleteEvent($connection, $booking->external_calendar_event_id);
                $booking->forceFill(['external_calendar_event_id' => null])->saveQuietly();
            }

            return;
        }

        $timezone = $booking->resource->location?->timezone ?? $booking->resource->organization->timezone;

        $event = [
            'summary' => "{$booking->service->name} — {$booking->customer->name}",
            'description' => "Booking Engine reservation {$booking->public_id}.",
            'start' => $booking->start_at,
            'end' => $booking->end_at,
            'timezone' => $timezone,
        ];

        if ($booking->external_calendar_event_id === null) {
            $externalId = $provider->createEvent($connection, $event);
            $booking->forceFill(['external_calendar_event_id' => $externalId])->saveQuietly();
        } else {
            $provider->updateEvent($connection, $booking->external_calendar_event_id, $event);
        }
    }
}
