<?php

namespace App\Application\Services;

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStateMachine;
use App\Domain\Payment\PaymentStatus;
use App\Http\Errors\ApiException;
use App\Http\Errors\ErrorCode;
use Stripe\Exception\ApiErrorException;

/**
 * Orchestrates the Stripe side of §30/§31: creating a PaymentIntent for
 * a booking that requires payment, and issuing refunds. Whether the
 * *booking* actually confirms once paid is BookingService's concern,
 * driven by the webhook (see StripeWebhookController /
 * ProcessPaymentWebhook, §32) — this class only ever touches Payment
 * rows.
 */
class PaymentService
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly PaymentStateMachine $stateMachine,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Creates a new Payment attempt and a matching Stripe PaymentIntent.
     * Always creates a fresh row rather than reusing a previous failed
     * attempt on this booking (see PaymentStateMachine) — guards against
     * a booking that doesn't need payment, isn't in a state that can
     * accept one, or already has an active (non-failed) payment.
     *
     * @return array{payment: Payment, client_secret: string}
     */
    public function createForBooking(Booking $booking): array
    {
        $service = $booking->service;

        if (! $service->payment_mode->requiresPayment()) {
            throw new ApiException(ErrorCode::ValidationFailed, "This booking's service does not require payment.", 422);
        }

        $stripeAccount = $booking->organization->stripeAccount;

        // Defense-in-depth: StoreServiceRequest/UpdateServiceRequest
        // already block a service from being set to a paid payment_mode
        // without a connected, charges-enabled account -- this only
        // fires if that connection was disconnected afterward.
        if ($stripeAccount === null || ! $stripeAccount->charges_enabled) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'This organization has not connected a Stripe account yet.',
                422,
            );
        }

        if (! in_array($booking->status, [BookingStatus::AwaitingPayment, BookingStatus::Confirmed], true)) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                "A booking with status \"{$booking->status->value}\" cannot accept a payment right now.",
                422,
            );
        }

        $latestPayment = $booking->payments()->latest()->first();

        if ($latestPayment !== null && $latestPayment->status !== PaymentStatus::Failed) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                "This booking already has a payment with status \"{$latestPayment->status->value}\".",
                422,
            );
        }

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => $service->amountOwed((string) $booking->price),
            'currency' => $booking->currency,
            'status' => PaymentStatus::Pending,
        ]);

        $this->auditLogger->log('payment.created', $payment, null, $payment->toArray());

        try {
            $intent = $this->stripe->createPaymentIntent($payment, $stripeAccount->stripe_account_id);
        } catch (ApiErrorException $e) {
            $this->stateMachine->transition($payment, PaymentStatus::Failed, ['failure_reason' => $e->getMessage()]);

            throw new ApiException(ErrorCode::PaymentFailed, 'Could not start payment with the provider.', 502);
        }

        $payment->forceFill(['provider_payment_id' => $intent->id])->save();

        return ['payment' => $payment, 'client_secret' => $intent->client_secret];
    }

    public function refund(Payment $payment, ?string $amount = null): Payment
    {
        if (! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                "A payment with status \"{$payment->status->value}\" cannot be refunded.",
                422,
            );
        }

        $remaining = bcsub((string) $payment->amount, (string) $payment->amount_refunded, 2);
        $refundAmount = $amount ?? $remaining;

        if (bccomp($refundAmount, $remaining, 2) > 0) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Refund amount exceeds the amount still available to refund.',
                422,
                ['amount' => ["Only {$remaining} {$payment->currency} is available to refund."]],
            );
        }

        $stripeAccount = $payment->booking->organization->stripeAccount;

        if ($stripeAccount === null) {
            // The organization disconnected Stripe after this payment was
            // taken -- there's no account left to route the refund
            // through. A genuine (if rare) operational dead end, not a
            // validation mistake by the caller.
            throw new ApiException(ErrorCode::PaymentFailed, 'The refund could not be processed: this organization no longer has a connected Stripe account.', 502);
        }

        try {
            $this->stripe->refund($payment, $stripeAccount->stripe_account_id, $amount);
        } catch (ApiErrorException $e) {
            throw new ApiException(ErrorCode::PaymentFailed, 'The refund could not be processed by the provider.', 502);
        }

        $newTotalRefunded = bcadd((string) $payment->amount_refunded, $refundAmount, 2);
        $isFullyRefunded = bccomp($newTotalRefunded, (string) $payment->amount, 2) >= 0;

        return $this->stateMachine->transition(
            $payment,
            $isFullyRefunded ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
            ['amount_refunded' => $newTotalRefunded],
        );
    }
}
