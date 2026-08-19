<?php

use App\Application\Services\StripeGateway;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use App\Models\User;
use Stripe\Event;

/**
 * Ties the whole §32/§33/§61 pipeline together in one test: webhook
 * receipt -> outbox write -> outbox:relay claims and dispatches ->
 * ProcessOutboxEvent fires the domain event -> ProcessPaymentWebhook
 * (queued on "payments") actually updates the Payment/Booking. Every
 * step here already has its own focused test elsewhere; this one is
 * the proof they fit together end to end.
 */
it('confirms a booking end to end from a Stripe webhook, through the outbox, to the queue', function () {
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
        'provider_payment_id' => 'pi_e2e_test',
        'status' => PaymentStatus::Pending,
    ]);

    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldReceive('verifyWebhookSignature')->once()->andReturn(
            Event::constructFrom([
                'id' => 'evt_e2e_test',
                'type' => 'payment_intent.succeeded',
                'data' => ['object' => ['id' => 'pi_e2e_test']],
            ]),
        );
    });

    $this->postJson('/api/v1/webhooks/stripe', [])->assertOk();

    // Nothing has actually run the payment/booking transition yet --
    // only a durable outbox row exists so far.
    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});
