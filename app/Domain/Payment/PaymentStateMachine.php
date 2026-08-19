<?php

namespace App\Domain\Payment;

use App\Application\Services\AuditLogger;
use App\Http\Errors\ApiException;
use App\Http\Errors\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Explicit allowed transitions for Payment::status (§30). Unlike
 * BookingStateMachine there's no separate history table -- audit_logs
 * (via AuditLogger, §49) already captures the same before/after,
 * and payments don't need the row-per-transition detail bookings do.
 * A failed payment is terminal on that row; retrying means creating a
 * new Payment (see PaymentService::createForBooking()), not resurrecting
 * the failed one, so the payment history stays an honest attempt log.
 */
class PaymentStateMachine
{
    /**
     * @var array<string, array<int, string>>
     */
    private const TRANSITIONS = [
        'pending' => ['authorized', 'paid', 'failed'],
        'authorized' => ['paid', 'failed'],
        'paid' => ['refunded', 'partially_refunded'],
        'partially_refunded' => ['refunded', 'partially_refunded'],
        'failed' => [],
        'refunded' => [],
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public static function canTransition(PaymentStatus $from, PaymentStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value], true);
    }

    /**
     * @param  array<string, mixed>  $extra  Additional columns to set alongside status (e.g. failure_reason, paid_at, amount_refunded).
     */
    public function transition(Payment $payment, PaymentStatus $to, array $extra = []): Payment
    {
        $from = $payment->status;

        if (! self::canTransition($from, $to)) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                "Payment cannot move from \"{$from->value}\" to \"{$to->value}\".",
                422,
                ['status' => ["Invalid transition from {$from->value} to {$to->value}."]],
            );
        }

        DB::transaction(function () use ($payment, $from, $to, $extra): void {
            $payment->forceFill(array_merge($extra, ['status' => $to]))->save();

            $this->auditLogger->log(
                "payment.{$to->value}",
                $payment,
                ['status' => $from->value],
                array_merge($extra, ['status' => $to->value]),
            );
        });

        return $payment;
    }
}
