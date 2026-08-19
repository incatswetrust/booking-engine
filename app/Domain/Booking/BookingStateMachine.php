<?php

namespace App\Domain\Booking;

use App\Application\Services\AuditLogger;
use App\Http\Errors\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\User;

/**
 * Explicit allowed transitions for Booking::status (§20). No caller is
 * allowed to set an arbitrary status — every change goes through
 * transition(), which validates the move and records it in
 * booking_status_history, and to audit_logs as "booking.<status>" (§49).
 */
class BookingStateMachine
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @var array<string, array<int, string>>
     */
    private const TRANSITIONS = [
        'pending' => ['held', 'awaiting_payment', 'confirmed', 'cancelled', 'expired'],
        'held' => ['awaiting_payment', 'confirmed', 'cancelled', 'expired'],
        'awaiting_payment' => ['confirmed', 'cancelled', 'expired'],
        'confirmed' => ['checked_in', 'cancelled', 'no_show'],
        'checked_in' => ['completed', 'no_show'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
        'expired' => [],
    ];

    public static function canTransition(BookingStatus $from, BookingStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value], true);
    }

    public function transition(Booking $booking, BookingStatus $to, ?User $actor = null): Booking
    {
        $from = $booking->status;

        if (! self::canTransition($from, $to)) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                "Booking cannot move from \"{$from->value}\" to \"{$to->value}\".",
                422,
                ['status' => ["Invalid transition from {$from->value} to {$to->value}."]],
            );
        }

        $booking->status = $to;

        if ($to === BookingStatus::Cancelled) {
            $booking->cancelled_at = now();
        }

        $booking->save();

        $booking->statusHistory()->create([
            'from_status' => $from->value,
            'to_status' => $to->value,
            'changed_by_user_id' => $actor?->id,
        ]);

        $this->auditLogger->log(
            "booking.{$to->value}",
            $booking,
            ['status' => $from->value],
            ['status' => $to->value],
        );

        return $booking;
    }
}
