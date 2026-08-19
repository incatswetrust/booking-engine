<?php

namespace App\Listeners;

use App\Domain\Calendar\CalendarConnectionStatus;
use App\Domain\Resource\Resource;
use App\Jobs\SyncBookingToCalendar;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Shared by the three concrete listeners below, mirroring
 * DispatchesWebhookDeliveries: this listener job's only responsibility
 * is deciding WHETHER a sync is needed (does the booking's resource
 * even have an active calendar connection?) so SyncBookingToCalendar
 * isn't queued pointlessly for every booking mutation across every
 * organization.
 */
abstract class SyncsBookingToCalendar implements ShouldQueue
{
    public string $queue = 'calendar';

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function dispatchFor(array $payload, string $action): void
    {
        $bookingPublicId = $payload['booking_id'] ?? null;
        $resourcePublicId = $payload['resource_id'] ?? null;

        if ($bookingPublicId === null || $resourcePublicId === null) {
            return;
        }

        $resource = Resource::where('public_id', $resourcePublicId)->first();
        $connection = $resource?->calendarConnection;

        if ($connection === null || $connection->status !== CalendarConnectionStatus::Active) {
            return;
        }

        SyncBookingToCalendar::dispatch($bookingPublicId, $action);
    }
}
