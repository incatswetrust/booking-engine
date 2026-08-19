<?php

use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookDeliveryStatus;
use App\Domain\Webhook\WebhookEndpoint;
use App\Jobs\DeliverWebhook;
use Illuminate\Support\Facades\Http;

it('marks a delivery delivered on a 2xx response', function () {
    Http::fake(['https://example.com/*' => Http::response('ok', 200)]);

    $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://example.com/hook']);
    $delivery = WebhookDelivery::factory()->for($endpoint, 'webhookEndpoint')->create();

    (new DeliverWebhook($delivery->id))->handle();

    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($delivery->status_code)->toBe(200)
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->duration_ms)->not->toBeNull();
});

it('throws and records the response on a non-2xx response, for the queue to retry', function () {
    Http::fake(['https://example.com/*' => Http::response('server error', 500)]);

    $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://example.com/hook']);
    $delivery = WebhookDelivery::factory()->for($endpoint, 'webhookEndpoint')->create();

    expect(fn () => (new DeliverWebhook($delivery->id))->handle())->toThrow(RuntimeException::class);

    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->status_code)->toBe(500)
        ->and($delivery->attempt)->toBe(1);
});

it('marks the delivery failed once the job gives up retrying', function () {
    $delivery = WebhookDelivery::factory()->create();

    (new DeliverWebhook($delivery->id))->failed(new RuntimeException('boom'));

    expect($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::Failed);
});

it('signs the request body with HMAC-SHA256 over timestamp.body', function () {
    Http::fake(['https://example.com/*' => Http::response('ok', 200)]);

    $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://example.com/hook', 'secret' => 'test-secret']);
    $delivery = WebhookDelivery::factory()->for($endpoint, 'webhookEndpoint')->create(['payload' => ['foo' => 'bar']]);

    (new DeliverWebhook($delivery->id))->handle();

    Http::assertSent(function ($request) {
        $timestamp = $request->header('X-Webhook-Timestamp')[0];
        $expected = hash_hmac('sha256', "{$timestamp}.".json_encode(['foo' => 'bar']), 'test-secret');

        return $request->header('X-Webhook-Signature')[0] === $expected;
    });
});

it('does not redeliver an already-delivered webhook', function () {
    Http::fake();

    $delivery = WebhookDelivery::factory()->create(['status' => WebhookDeliveryStatus::Delivered]);

    (new DeliverWebhook($delivery->id))->handle();

    Http::assertNothingSent();
});
