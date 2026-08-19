<?php

namespace App\Domain\ApiKey;

/**
 * Fixed scope set from §45 — checked by App\Http\Middleware\EnsureApiKeyScope,
 * layered on top of (not replacing) the normal Policy/Permission checks,
 * since an API key authenticates AS its creating user (see ApiKey::user()).
 */
enum ApiKeyScope: string
{
    case BookingsRead = 'bookings:read';
    case BookingsWrite = 'bookings:write';
    case AvailabilityRead = 'availability:read';
    case ResourcesRead = 'resources:read';
}
