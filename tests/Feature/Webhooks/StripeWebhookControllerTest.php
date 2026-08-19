<?php

use App\Application\Services\StripeGateway;
use App\Domain\Outbox\OutboxMessage;
use App\Domain\Payment\PaymentWebhookEvent;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;

/**
 * The controller's job is narrow: verify signature, dedupe by
 * event_id, write to the outbox, ack fast. What actually happens to a
 * Payment/Booking is covered by ProcessPaymentWebhookTest.php (the
 * listener) and StripeWebhookEndToEndTest.php (the full pipeline).
 */
function fakeStripeEvent(string $id = 'evt_test123', string $type = 'payment_intent.succeeded', array $object = ['id' => 'pi_test123']): Event
{
    return Event::constructFrom(['id' => $id, 'type' => $type, 'data' => ['object' => $object]]);
}

it('rejects a webhook with an invalid signature', function () {
    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldReceive('verifyWebhookSignature')->once()->andThrow(
            new SignatureVerificationException('No signatures found matching the expected signature for payload'),
        );
    });

    $this->postJson('/api/v1/webhooks/stripe', ['type' => 'payment_intent.succeeded'])
        ->assertStatus(400);

    expect(PaymentWebhookEvent::count())->toBe(0)
        ->and(OutboxMessage::count())->toBe(0);
});

it('records a new event and writes it to the outbox', function () {
    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldReceive('verifyWebhookSignature')->once()->andReturn(fakeStripeEvent());
    });

    $this->postJson('/api/v1/webhooks/stripe', [])->assertOk()->assertJsonPath('status', 'accepted');

    $webhookEvent = PaymentWebhookEvent::firstOrFail();
    expect($webhookEvent->event_id)->toBe('evt_test123')
        ->and($webhookEvent->type)->toBe('payment_intent.succeeded');

    $message = OutboxMessage::where('aggregate_type', 'PaymentWebhookEvent')
        ->where('aggregate_id', (string) $webhookEvent->id)
        ->firstOrFail();

    expect($message->event_type)->toBe('StripeWebhookReceived')
        ->and($message->payload['stripe_event_type'])->toBe('payment_intent.succeeded')
        ->and($message->payload['stripe_object']['id'])->toBe('pi_test123');
});

it('does not reprocess an event_id it has already seen', function () {
    $this->mock(StripeGateway::class, function ($mock) {
        $mock->shouldReceive('verifyWebhookSignature')->twice()->andReturn(fakeStripeEvent());
    });

    $this->postJson('/api/v1/webhooks/stripe', [])->assertOk()->assertJsonPath('status', 'accepted');
    $this->postJson('/api/v1/webhooks/stripe', [])->assertOk()->assertJsonPath('status', 'already_received');

    expect(PaymentWebhookEvent::count())->toBe(1)
        ->and(OutboxMessage::count())->toBe(1);
});
