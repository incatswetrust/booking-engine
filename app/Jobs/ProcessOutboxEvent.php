<?php

namespace App\Jobs;

use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\Booking\Events\BookingConfirmed;
use App\Domain\Booking\Events\BookingCreated;
use App\Domain\Booking\Events\BookingRescheduled;
use App\Domain\Outbox\OutboxMessage;
use App\Domain\Outbox\OutboxStatus;
use App\Domain\Payment\Events\StripeWebhookReceived;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * The actual delivery step of the Transactional Outbox (§33, §61): a real
 * Redis-backed queued job, run by Horizon, so this is genuine background
 * job execution rather than work done inline in the polling command.
 * OutboxRelay's only responsibility is to claim a row and dispatch this
 * job — everything about firing the domain event and updating the
 * outbox_messages row happens here, retried by Laravel's own queue
 * machinery instead of a hand-rolled loop.
 *
 * StripeWebhookReceived is listened to by App\Listeners\
 * ProcessPaymentWebhook (§32) — reusing this exact same pipeline is what
 * makes Stripe webhook processing durable across a Redis outage, the
 * same guarantee the outbox already gave bookings.
 */
class ProcessOutboxEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @var array<string, class-string>
     */
    private const EVENT_CLASSES = [
        'BookingCreated' => BookingCreated::class,
        'BookingConfirmed' => BookingConfirmed::class,
        'BookingCancelled' => BookingCancelled::class,
        'BookingRescheduled' => BookingRescheduled::class,
        'StripeWebhookReceived' => StripeWebhookReceived::class,
    ];

    public function __construct(public readonly int $outboxMessageId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(): void
    {
        $message = OutboxMessage::find($this->outboxMessageId);

        if ($message === null) {
            return;
        }

        try {
            $eventClass = self::EVENT_CLASSES[$message->event_type] ?? null;

            if ($eventClass === null) {
                throw new RuntimeException("No event class registered for outbox event_type \"{$message->event_type}\".");
            }

            event(new $eventClass($message->aggregate_id, $message->payload));

            $message->update(['status' => OutboxStatus::Processed, 'processed_at' => now()]);
        } catch (Throwable $e) {
            $message->increment('attempts');
            $message->update(['error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        OutboxMessage::where('id', $this->outboxMessageId)->update([
            'status' => OutboxStatus::Failed,
            'error' => $exception?->getMessage(),
        ]);
    }
}
