<?php

namespace App\Domain\Calendar;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * §38: interface-only stub -- Outlook integration itself is Phase 4.
 * Exists so CalendarConnection.provider can already accept "outlook"
 * as a value and CalendarProviderResolver has something concrete to
 * resolve it to, without implicitly pretending Google is the only
 * provider the abstraction supports.
 */
class OutlookCalendarProvider implements CalendarProviderInterface
{
    public function authorizationUrl(string $state): string
    {
        throw new RuntimeException('Outlook Calendar integration is not implemented yet (Phase 4).');
    }

    public function exchangeCode(string $code): array
    {
        throw new RuntimeException('Outlook Calendar integration is not implemented yet (Phase 4).');
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        throw new RuntimeException('Outlook Calendar integration is not implemented yet (Phase 4).');
    }

    public function getBusyPeriods(CalendarConnection $connection, CarbonInterface $from, CarbonInterface $to): array
    {
        throw new RuntimeException('Outlook Calendar integration is not implemented yet (Phase 4).');
    }

    public function createEvent(CalendarConnection $connection, array $event): string
    {
        throw new RuntimeException('Outlook Calendar integration is not implemented yet (Phase 4).');
    }

    public function updateEvent(CalendarConnection $connection, string $externalEventId, array $event): void
    {
        throw new RuntimeException('Outlook Calendar integration is not implemented yet (Phase 4).');
    }

    public function deleteEvent(CalendarConnection $connection, string $externalEventId): void
    {
        throw new RuntimeException('Outlook Calendar integration is not implemented yet (Phase 4).');
    }
}
