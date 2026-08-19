<?php

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Domain\Payment\Events\StripeWebhookReceived;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use App\Listeners\ProcessPaymentWebhook;
use App\Models\User;
use App\Notifications\PaymentFailedNotification;
use Illuminate\Support\Facades\Notification;

function makeAwaitingPaymentBooking(): array
{
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['payment_mode' => 'full']);

    $booking = Booking::factory()->create([
        'organization_id' => $organization->id,
        'resource_id' => $resource->id,
        'service_id' => $service->id,
        'location_id' => $resource->location_id,
        'customer_id' => User::factory()->create()->id,
        'status' => BookingStatus::AwaitingPayment,
    ]);

    $payment = Payment::factory()->for($booking)->create([
        'provider_payment_id' => 'pi_listener_test',
        'status' => PaymentStatus::Pending,
    ]);

    return [$booking, $payment];
}

it('marks the payment paid and confirms the booking on payment_intent.succeeded', function () {
    [$booking, $payment] = makeAwaitingPaymentBooking();

    app(ProcessPaymentWebhook::class)->handle(new StripeWebhookReceived('1', [
        'stripe_event_type' => 'payment_intent.succeeded',
        'stripe_object' => ['id' => 'pi_listener_test'],
    ]));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->paid_at)->not->toBeNull()
        ->and($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

it('marks the payment failed, notifies the customer, and leaves the booking in awaiting_payment on payment_intent.payment_failed', function () {
    Notification::fake();

    [$booking, $payment] = makeAwaitingPaymentBooking();

    app(ProcessPaymentWebhook::class)->handle(new StripeWebhookReceived('1', [
        'stripe_event_type' => 'payment_intent.payment_failed',
        'stripe_object' => ['id' => 'pi_listener_test', 'last_payment_error' => ['message' => 'Your card was declined.']],
    ]));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe('Your card was declined.')
        ->and($booking->refresh()->status)->toBe(BookingStatus::AwaitingPayment);

    Notification::assertSentTo(
        $booking->customer,
        PaymentFailedNotification::class,
        fn ($notification) => in_array('Reason: Your card was declined.', $notification->toMail($booking->customer)->introLines, true),
    );
});

it('ignores an unrecognized provider_payment_id', function () {
    [, $payment] = makeAwaitingPaymentBooking();

    app(ProcessPaymentWebhook::class)->handle(new StripeWebhookReceived('1', [
        'stripe_event_type' => 'payment_intent.succeeded',
        'stripe_object' => ['id' => 'pi_does_not_exist'],
    ]));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('ignores a redelivered event for a payment that is no longer pending', function () {
    [, $payment] = makeAwaitingPaymentBooking();
    $payment->update(['status' => PaymentStatus::Paid, 'paid_at' => now()]);

    // A second payment_intent.succeeded for the same intent (e.g. Stripe
    // redelivering, or a different event_id for the same underlying
    // change) must not touch a payment that's already settled.
    app(ProcessPaymentWebhook::class)->handle(new StripeWebhookReceived('2', [
        'stripe_event_type' => 'payment_intent.succeeded',
        'stripe_object' => ['id' => 'pi_listener_test'],
    ]));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid);
});

it('ignores Stripe event types it does not act on', function () {
    [, $payment] = makeAwaitingPaymentBooking();

    app(ProcessPaymentWebhook::class)->handle(new StripeWebhookReceived('1', [
        'stripe_event_type' => 'charge.dispute.created',
        'stripe_object' => ['id' => 'pi_listener_test'],
    ]));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});
