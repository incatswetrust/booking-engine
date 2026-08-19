<?php

namespace App\Console\Commands;

use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\Booking\Events\BookingConfirmed;
use App\Domain\Booking\Events\BookingCreated;
use App\Domain\Booking\Events\BookingRescheduled;
use App\Domain\Outbox\OutboxMessage;
use App\Domain\Outbox\OutboxStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The other half of the Transactional Outbox (§33): BookingService and
 * BookingStateMachine only ever write rows to outbox_messages, inside
 * the same DB transaction as the change they describe. This command is
 * the "worker" the spec refers to — it polls that table separately from
 * any queue/broker, so a Redis outage at write time can't lose an
 * event, only delay its relay.
 *
 * No listeners exist yet for these events (Phase 2 will add
 * SendConfirmationEmail, SyncGoogleCalendar, etc. against them) — firing
 * them here now still proves the write -> relay -> dispatch pipeline
 * works end to end, and Phase 2 listeners will "just work" once
 * registered, without touching this command or the writers.
 */
class OutboxRelay extends Command
{
    protected $signature = 'outbox:relay
        {--once : Process a single batch and exit, instead of looping forever}
        {--batch=50 : Max messages to claim per poll}
        {--sleep=1 : Seconds to sleep between polls when not using --once}
        {--max-attempts=5 : Give up (mark failed) after this many failed attempts}';

    protected $description = 'Relay pending outbox_messages rows as real domain events (§33-35)';

    /**
     * @var array<string, class-string>
     */
    private const EVENT_CLASSES = [
        'BookingCreated' => BookingCreated::class,
        'BookingConfirmed' => BookingConfirmed::class,
        'BookingCancelled' => BookingCancelled::class,
        'BookingRescheduled' => BookingRescheduled::class,
    ];

    public function handle(): int
    {
        $once = (bool) $this->option('once');

        do {
            $processed = $this->relayBatch((int) $this->option('batch'), (int) $this->option('max-attempts'));

            if (! $once && $processed === 0) {
                sleep((int) $this->option('sleep'));
            }
        } while (! $once);

        return self::SUCCESS;
    }

    private function relayBatch(int $batchSize, int $maxAttempts): int
    {
        $messages = DB::transaction(function () use ($batchSize) {
            $messages = OutboxMessage::query()
                ->where('status', OutboxStatus::Pending)
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->limit($batchSize)
                ->lockForUpdate()
                ->get();

            $messages->each(fn (OutboxMessage $m) => $m->update(['status' => OutboxStatus::Processing]));

            return $messages;
        });

        foreach ($messages as $message) {
            $this->relayOne($message, $maxAttempts);
        }

        return $messages->count();
    }

    private function relayOne(OutboxMessage $message, int $maxAttempts): void
    {
        try {
            $eventClass = self::EVENT_CLASSES[$message->event_type] ?? null;

            if ($eventClass === null) {
                throw new \RuntimeException("No event class registered for outbox event_type \"{$message->event_type}\".");
            }

            event(new $eventClass($message->aggregate_id, $message->payload));

            $message->update(['status' => OutboxStatus::Processed, 'processed_at' => now()]);
        } catch (Throwable $e) {
            $attempts = $message->attempts + 1;

            Log::warning('Outbox relay failed to process message', [
                'outbox_message_id' => $message->id,
                'event_type' => $message->event_type,
                'attempt' => $attempts,
                'error' => $e->getMessage(),
            ]);

            $message->update([
                'attempts' => $attempts,
                'error' => $e->getMessage(),
                'status' => $attempts >= $maxAttempts ? OutboxStatus::Failed : OutboxStatus::Pending,
                // Exponential backoff, capped at 5 minutes.
                'available_at' => now()->addSeconds(min(2 ** $attempts, 300)),
            ]);
        }
    }
}
