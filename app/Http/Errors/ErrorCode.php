<?php

namespace App\Http\Errors;

/**
 * Stable, client-facing error identifiers (§53). New domains extend this
 * list instead of inventing ad-hoc strings in individual controllers.
 */
enum ErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';

    case AuthenticationRequired = 'AUTHENTICATION_REQUIRED';
    case PermissionDenied = 'PERMISSION_DENIED';

    case ResourceNotFound = 'RESOURCE_NOT_FOUND';

    case BookingSlotUnavailable = 'BOOKING_SLOT_UNAVAILABLE';
    case BookingAlreadyCancelled = 'BOOKING_ALREADY_CANCELLED';
    case BookingCannotBeRescheduled = 'BOOKING_CANNOT_BE_RESCHEDULED';

    case PaymentRequired = 'PAYMENT_REQUIRED';
    case PaymentFailed = 'PAYMENT_FAILED';

    case RateLimitExceeded = 'RATE_LIMIT_EXCEEDED';

    case IdempotencyConflict = 'IDEMPOTENCY_CONFLICT';

    case InternalError = 'INTERNAL_ERROR';
}
