<?php

namespace App\Domain\Calendar;

use InvalidArgumentException;

/**
 * A CalendarConnection stores which provider it belongs to as a plain
 * string column, so sync jobs/listeners resolve the concrete
 * CalendarProviderInterface implementation through here rather than
 * binding one provider directly in the container.
 */
class CalendarProviderResolver
{
    public function resolve(string $provider): CalendarProviderInterface
    {
        return match ($provider) {
            'google' => app(GoogleCalendarProvider::class),
            'outlook' => app(OutlookCalendarProvider::class),
            default => throw new InvalidArgumentException("Unknown calendar provider \"{$provider}\"."),
        };
    }
}
