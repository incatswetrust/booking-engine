<?php

namespace App\Jobs;

use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookDeliveryStatus;
use App\Infrastructure\Metrics\Metrics;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * §42/§43: one job instance is retried in place across all attempts
 * (via Laravel's own queue backoff, same pattern as ProcessOutboxEvent/
 * ProcessPaymentWebhook) rather than creating a new WebhookDelivery row
 * per attempt -- "attempt" on the row is incremented, matching §42's
 * schema. A manual retry (WebhookDeliveryController::retry, for a
 * delivery that already exhausted all 5 tries) dispatches a fresh job
 * instance for the same row, giving it a new 5-try budget.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public string $queue = 'webhooks';

    public int $tries = 5;

    public function __construct(public readonly int $webhookDeliveryId) {}

    /**
     * §43's example backoff: 1m, 5m, 30m, 2h, 12h.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 43200];
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::with('webhookEndpoint')->find($this->webhookDeliveryId);

        if ($delivery === null || $delivery->status === WebhookDeliveryStatus::Delivered) {
            return;
        }

        $endpoint = $delivery->webhookEndpoint;
        $delivery->increment('attempt');

        $body = json_encode($delivery->payload);
        $timestamp = (string) now()->timestamp;
        // §44: HMAC over timestamp+body (not the body alone) so a
        // captured payload+signature pair can't be replayed indefinitely
        // -- the receiver checks the timestamp is recent as well as
        // recomputing the signature.
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $endpoint->secret);

        $startedAt = microtime(true);

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'X-Webhook-Signature' => $signature,
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Event' => $delivery->event_type,
                    'X-Webhook-Id' => $delivery->public_id,
                ])
                ->timeout(10)
                ->post($endpoint->url);

            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            $delivery->update([
                'status_code' => $response->status(),
                'response_body' => Str::limit($response->body(), 2000),
                'duration_ms' => $durationMs,
            ]);

            if ($response->successful()) {
                $delivery->update(['status' => WebhookDeliveryStatus::Delivered, 'next_retry_at' => null]);

                Metrics::webhookDelivery(success: true, durationMs: $durationMs);

                return;
            }

            throw new RuntimeException("Webhook endpoint responded with status {$response->status()}.");
        } catch (Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            if ($delivery->status_code === null) {
                $delivery->update([
                    'response_body' => Str::limit($e->getMessage(), 2000),
                    'duration_ms' => $durationMs,
                ]);
            }

            Metrics::webhookDelivery(success: false, durationMs: $durationMs);

            // Purely informational -- Laravel's own queue backoff is what
            // actually schedules the retry, this just keeps
            // GET /webhook-deliveries honest about when that'll be.
            $backoffSchedule = $this->backoff();
            $backoffSeconds = $backoffSchedule[$this->attempts() - 1] ?? end($backoffSchedule);
            $delivery->update(['next_retry_at' => now()->addSeconds($backoffSeconds)]);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        WebhookDelivery::where('id', $this->webhookDeliveryId)->update([
            'status' => WebhookDeliveryStatus::Failed,
            'next_retry_at' => null,
        ]);
    }
}
