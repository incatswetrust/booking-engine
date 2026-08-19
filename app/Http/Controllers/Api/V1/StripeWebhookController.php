<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\OutboxWriter;
use App\Application\Services\StripeGateway;
use App\Domain\Payment\Events\StripeWebhookReceived;
use App\Domain\Payment\PaymentWebhookEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Stripe\Exception\SignatureVerificationException;

/**
 * §32: verifies the Stripe signature, records the event_id so a Stripe
 * retry (it retries aggressively on anything but 2xx) can never process
 * an event twice, then hands off to the outbox (§33) instead of doing
 * any real work inline — Stripe expects a fast ack, not a wait on
 * however long PaymentStateMachine/BookingStateMachine take.
 */
#[OA\Tag(name: 'Webhooks')]
class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly OutboxWriter $outboxWriter,
    ) {}

    #[OA\Post(
        path: '/api/v1/webhooks/stripe',
        summary: 'Stripe webhook endpoint (§32) — not authenticated via Sanctum, verified via Stripe-Signature instead',
        tags: ['Webhooks'],
        responses: [
            new OA\Response(response: 200, description: 'Event accepted (or already seen)'),
            new OA\Response(response: 400, description: 'Invalid signature'),
        ],
    )]
    public function handle(Request $request): JsonResponse
    {
        try {
            $event = $this->stripe->verifyWebhookSignature(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
            );
        } catch (SignatureVerificationException) {
            return response()->json(['error' => ['code' => 'INVALID_SIGNATURE']], 400);
        }

        $alreadySeen = PaymentWebhookEvent::where('provider', 'stripe')
            ->where('event_id', $event->id)
            ->exists();

        if ($alreadySeen) {
            return response()->json(['status' => 'already_received']);
        }

        $webhookEvent = PaymentWebhookEvent::create([
            'provider' => 'stripe',
            'event_id' => $event->id,
            'type' => $event->type,
        ]);

        $this->outboxWriter->record(
            class_basename(StripeWebhookReceived::class),
            $webhookEvent,
            [
                'stripe_event_type' => $event->type,
                'stripe_object' => $event->data->object->toArray(),
            ],
        );

        return response()->json(['status' => 'accepted']);
    }
}
