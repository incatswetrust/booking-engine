<?php

namespace App\Listeners;

use App\Domain\Booking\BookingStateMachine;
use App\Domain\Booking\BookingStatus;
use App\Domain\Payment\Events\StripeWebhookReceived;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStateMachine;
use App\Domain\Payment\PaymentStatus;
use App\Notifications\PaymentFailedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * §32: reacts to the two Stripe PaymentIntent events the app cares
 * about. Fired by ProcessOutboxEvent (the outbox relay's queued job) —
 * but this listener itself implements ShouldQueue with its own "payments"
 * queue (§61's literal "Job ProcessPaymentWebhook, очередь payments"),
 * so Laravel queues a second, separate job for it instead of running it
 * inline inside the relay's job. Two layers of durability: the outbox
 * write survives a Redis outage at webhook-receipt time, and this queued
 * listener gets its own independent retry/backoff on top.
 *
 * Idempotent by construction, independent of the payment_webhook_events
 * dedup at the controller: both branches bail out unless the payment is
 * still "pending", so a redelivered event (a different Stripe event_id
 * for the same underlying intent, or a retry of this listener after a
 * transient failure) can never double-transition it.
 */
class ProcessPaymentWebhook implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'payments';

    public int $tries = 5;

    public function __construct(
        private readonly PaymentStateMachine $paymentStateMachine,
        private readonly BookingStateMachine $bookingStateMachine,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(StripeWebhookReceived $event): void
    {
        $type = $event->payload['stripe_event_type'] ?? null;
        $object = $event->payload['stripe_object'] ?? [];

        match ($type) {
            'payment_intent.succeeded' => $this->handleSucceeded($object),
            'payment_intent.payment_failed' => $this->handleFailed($object),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function handleSucceeded(array $object): void
    {
        $payment = $this->findPendingPayment($object);

        if ($payment === null) {
            return;
        }

        $this->paymentStateMachine->transition($payment, PaymentStatus::Paid, ['paid_at' => now()]);

        $booking = $payment->booking;

        if ($booking->status === BookingStatus::AwaitingPayment) {
            $this->bookingStateMachine->transition($booking, BookingStatus::Confirmed);
        }
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function handleFailed(array $object): void
    {
        $payment = $this->findPendingPayment($object);

        if ($payment === null) {
            return;
        }

        $reason = $object['last_payment_error']['message'] ?? 'The payment was declined.';

        $this->paymentStateMachine->transition($payment, PaymentStatus::Failed, ['failure_reason' => $reason]);

        $payment->booking->customer->notify(new PaymentFailedNotification([
            ...$payment->booking->toOutboxPayload(),
            'failure_reason' => $reason,
        ]));
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function findPendingPayment(array $object): ?Payment
    {
        $providerPaymentId = $object['id'] ?? null;

        if ($providerPaymentId === null) {
            return null;
        }

        $payment = Payment::where('provider_payment_id', $providerPaymentId)->first();

        return $payment?->status === PaymentStatus::Pending ? $payment : null;
    }

    public function failed(StripeWebhookReceived $event, Throwable $exception): void
    {
        Log::error('Stripe webhook processing failed after exhausting retries', [
            'webhook_event_id' => $event->webhookEventId,
            'stripe_event_type' => $event->payload['stripe_event_type'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
