<?php

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Domain\Payment\Events\StripeWebhookReceived;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookDeliveryStatus;
use App\Domain\Webhook\WebhookEndpoint;
use App\Listeners\ProcessPaymentWebhook;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Full pipeline, same shape as StripeWebhookEndToEndTest: booking action
 * -> outbox write -> outbox:relay -> ProcessOutboxEvent fires the domain
 * event -> the Dispatch*Webhooks listener (queued on "webhooks") creates
 * a WebhookDelivery and dispatches DeliverWebhook -- which, under the
 * "sync" queue driver used in tests, runs immediately and makes a real
 * (Http::fake()'d) HTTP call.
 */
it('delivers a signed webhook when a subscribed booking event fires', function () {
    Http::fake(['https://example.com/*' => Http::response('ok', 200)]);

    $organization = Organization::factory()->create();
    $endpoint = WebhookEndpoint::factory()->for($organization)->create([
        'url' => 'https://example.com/webhooks/booking',
        'events' => ['booking.confirmed'],
    ]);

    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated();

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    $delivery = WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)->firstOrFail();
    expect($delivery->event_type)->toBe('booking.confirmed')
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($delivery->status_code)->toBe(200)
        ->and($delivery->payload['booking_id'])->toBe($response->json('data.id'));

    Http::assertSent(function ($request) use ($endpoint) {
        $body = $request->body();
        $expectedSignature = hash_hmac('sha256', "{$request->header('X-Webhook-Timestamp')[0]}.{$body}", $endpoint->secret);

        return $request->url() === 'https://example.com/webhooks/booking'
            && $request->header('X-Webhook-Event')[0] === 'booking.confirmed'
            && $request->header('X-Webhook-Signature')[0] === $expectedSignature
            && $request->hasHeader('X-Webhook-Id')
            && $request->hasHeader('X-Webhook-Timestamp');
    });
});

it('does not deliver to an endpoint not subscribed to the event', function () {
    Http::fake();

    $organization = Organization::factory()->create();
    WebhookEndpoint::factory()->for($organization)->create(['events' => ['payment.completed']]);

    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated();

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    expect(WebhookDelivery::count())->toBe(0);
    Http::assertNothingSent();
});

it('does not deliver to a disabled endpoint', function () {
    Http::fake();

    $organization = Organization::factory()->create();
    WebhookEndpoint::factory()->for($organization)->create([
        'events' => ['booking.confirmed'],
        'status' => 'disabled',
    ]);

    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated();

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    expect(WebhookDelivery::count())->toBe(0);
});

it('delivers a payment.completed webhook when a Stripe webhook confirms payment', function () {
    Http::fake(['https://example.com/*' => Http::response('ok', 200)]);

    $organization = Organization::factory()->create();
    WebhookEndpoint::factory()->for($organization)->create([
        'url' => 'https://example.com/webhooks/payments',
        'events' => ['payment.completed'],
    ]);

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
        'provider_payment_id' => 'pi_webhook_dispatch_test',
        'status' => PaymentStatus::Pending,
    ]);

    app(ProcessPaymentWebhook::class)->handle(new StripeWebhookReceived('1', [
        'stripe_event_type' => 'payment_intent.succeeded',
        'stripe_object' => ['id' => 'pi_webhook_dispatch_test'],
    ]));

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    $delivery = WebhookDelivery::firstOrFail();
    expect($delivery->event_type)->toBe('payment.completed')
        ->and($delivery->payload['payment_id'])->toBe($payment->public_id)
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Delivered);
});
