<?php

namespace App\Domain\Calendar;

use Carbon\CarbonInterface;

/**
 * §38: abstraction over external calendar providers, so
 * GoogleCalendarProvider and (Phase 4) OutlookCalendarProvider can be
 * swapped without touching the sync jobs/listeners that drive them.
 */
interface CalendarProviderInterface
{
    /**
     * Where to send the user to grant access. $state is an opaque,
     * server-generated token the callback uses to recover which
     * resource initiated the flow -- never trust a client-supplied
     * resource id here.
     */
    public function authorizationUrl(string $state): string;

    /**
     * Exchanges an OAuth "code" for tokens right after the provider
     * redirects back to our callback.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, external_calendar_id: ?string}
     */
    public function exchangeCode(string $code): array;

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function refreshAccessToken(string $refreshToken): array;

    /**
     * §37: busy periods on the provider's calendar in [$from, $to),
     * used to widen what the Availability Engine treats as occupied.
     *
     * @return array<int, array{start: CarbonInterface, end: CarbonInterface}>
     */
    public function getBusyPeriods(CalendarConnection $connection, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * @param  array{summary: string, description: string, start: CarbonInterface, end: CarbonInterface, timezone: string}  $event
     * @return string the provider's event id, to be stored for later update/delete
     */
    public function createEvent(CalendarConnection $connection, array $event): string;

    /**
     * @param  array{summary: string, description: string, start: CarbonInterface, end: CarbonInterface, timezone: string}  $event
     */
    public function updateEvent(CalendarConnection $connection, string $externalEventId, array $event): void;

    public function deleteEvent(CalendarConnection $connection, string $externalEventId): void;
}
