<?php

namespace App\Application\Services;

use App\Domain\Payment\Payment;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Thin wrapper around the Stripe SDK (§30, §31, §32) — kept to exactly
 * the three operations the rest of the app needs, so callers depend on
 * this instead of touching \Stripe\* directly.
 */
class StripeGateway
{
    private StripeClient $client;

    public function __construct(?string $secret = null)
    {
        // The container resolves this eagerly for every request that
        // touches BookingService/PaymentService, whether or not that
        // request ever actually calls Stripe -- so this must never throw
        // just because no key is configured yet. StripeClient rejects
        // both null and '', so an obviously-fake placeholder stands in
        // until a real STRIPE_SECRET is set; an actual API call with it
        // would fail with a Stripe auth error, not a local exception.
        $this->client = new StripeClient($secret ?? (config('services.stripe.secret') ?: 'sk_test_not_configured'));
    }

    /**
     * Idempotency-keyed on the Payment's own public_id, so a retried
     * request (e.g. the caller's HTTP call times out and retries) can
     * never create two PaymentIntents for the same Payment row.
     */
    public function createPaymentIntent(Payment $payment): PaymentIntent
    {
        return $this->client->paymentIntents->create(
            [
                'amount' => $this->toMinorUnits($payment->amount, $payment->currency),
                'currency' => strtolower($payment->currency),
                'metadata' => [
                    'payment_id' => $payment->public_id,
                    'booking_id' => (string) $payment->booking->public_id,
                ],
            ],
            ['idempotency_key' => "payment-intent:{$payment->public_id}"],
        );
    }

    public function refund(Payment $payment, ?string $amount = null): Refund
    {
        $params = ['payment_intent' => $payment->provider_payment_id];

        if ($amount !== null) {
            $params['amount'] = $this->toMinorUnits($amount, $payment->currency);
        }

        return $this->client->refunds->create(
            $params,
            ['idempotency_key' => 'refund:'.$payment->public_id.':'.($amount ?? 'full').':'.now()->timestamp],
        );
    }

    /**
     * @throws SignatureVerificationException if the signature doesn't match — the caller should treat that as a rejected webhook, not a retry-able failure.
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader): Event
    {
        return Webhook::constructEvent($payload, $signatureHeader, config('services.stripe.webhook_secret'));
    }

    /**
     * Stripe amounts are integer minor units (cents), except for a
     * small set of zero-decimal currencies where the "minor unit" IS
     * the whole unit — JPY being the practically relevant one here.
     */
    private function toMinorUnits(string $amount, string $currency): int
    {
        $zeroDecimal = in_array(strtoupper($currency), ['JPY', 'KRW', 'VND', 'CLP'], true);

        return $zeroDecimal
            ? (int) round((float) $amount)
            : (int) round((float) $amount * 100);
    }
}
