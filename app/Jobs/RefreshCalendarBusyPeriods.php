<?php

namespace App\Jobs;

use App\Domain\Calendar\CalendarConnection;
use App\Domain\Calendar\CalendarConnectionStatus;
use App\Domain\Calendar\CalendarProviderResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * §37/§62: dispatched once per active connection, every 5 minutes, by
 * the `calendar:refresh-busy-periods` scheduled command -- keeps
 * AvailabilityService reading from a cache instead of calling the
 * provider on the request path. A connection whose refresh token no
 * longer works (revoked access, expired grant) is marked Error rather
 * than silently retried forever; reconnecting clears it.
 */
class RefreshCalendarBusyPeriods implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public string $queue = 'calendar';

    public int $tries = 3;

    /**
     * How far ahead to cache busy periods for -- generous relative to
     * how often this runs, without trying to match each organization's
     * own booking_max_days_ahead setting.
     */
    private const HORIZON_DAYS = 30;

    public function __construct(public readonly int $calendarConnectionId) {}

    public function handle(CalendarProviderResolver $providers): void
    {
        $connection = CalendarConnection::find($this->calendarConnectionId);

        if ($connection === null || $connection->status !== CalendarConnectionStatus::Active) {
            return;
        }

        $provider = $providers->resolve($connection->provider);

        if ($connection->isExpired()) {
            try {
                $tokens = $provider->refreshAccessToken($connection->refresh_token);
            } catch (Throwable $e) {
                $connection->update(['status' => CalendarConnectionStatus::Error]);

                throw $e;
            }

            $connection->update([
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'token_expires_at' => now()->addSeconds($tokens['expires_in']),
            ]);
        }

        try {
            $busy = $provider->getBusyPeriods($connection, now(), now()->addDays(self::HORIZON_DAYS));
        } catch (Throwable $e) {
            $connection->update(['status' => CalendarConnectionStatus::Error]);

            throw $e;
        }

        $connection->update([
            'busy_periods' => array_map(fn (array $period) => [
                'start' => $period['start']->toIso8601String(),
                'end' => $period['end']->toIso8601String(),
            ], $busy),
            'busy_periods_synced_at' => now(),
        ]);
    }
}
