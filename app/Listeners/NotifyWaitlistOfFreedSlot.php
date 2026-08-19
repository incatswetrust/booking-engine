<?php

namespace App\Listeners;

use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use App\Domain\Waitlist\WaitlistEntry;
use App\Domain\Waitlist\WaitlistStatus;
use App\Notifications\WaitlistAvailableNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * §29: BookingCancelled -> WaitlistService -> Notification. Reached
 * through the same outbox pipeline as the other booking notifications
 * (SendBookingCancelledNotification etc.) rather than a bespoke
 * "WaitlistService" class — the matching logic here plays that role.
 *
 * Matches on an exact desired_start_at, since §29 frames this as "the
 * user can subscribe to THAT slot" rather than a fuzzy time range.
 * resource_id null means "any resource offering this service" — matched
 * against whichever resource actually just freed up.
 */
class NotifyWaitlistOfFreedSlot implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(BookingCancelled $event): void
    {
        $service = Service::where('public_id', $event->payload['service_id'])->first();
        $resource = Resource::where('public_id', $event->payload['resource_id'])->first();

        if ($service === null || $resource === null) {
            return;
        }

        $desiredStartAt = CarbonImmutable::parse($event->payload['start_at'])->utc()->format('Y-m-d H:i:s');

        WaitlistEntry::query()
            ->where('status', WaitlistStatus::Waiting)
            ->where('service_id', $service->id)
            ->where(fn ($q) => $q->whereNull('resource_id')->orWhere('resource_id', $resource->id))
            ->where('desired_start_at', $desiredStartAt)
            ->with('customer')
            ->get()
            ->each(function (WaitlistEntry $entry) use ($event) {
                $entry->customer->notify(new WaitlistAvailableNotification($event->payload));
                $entry->update(['status' => WaitlistStatus::Notified]);
            });
    }
}
