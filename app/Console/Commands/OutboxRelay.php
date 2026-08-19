<?php

namespace App\Console\Commands;

use App\Domain\Outbox\OutboxMessage;
use App\Domain\Outbox\OutboxStatus;
use App\Jobs\ProcessOutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The other half of the Transactional Outbox (§33): BookingService and
 * BookingStateMachine only ever write rows to outbox_messages, inside
 * the same DB transaction as the change they describe. This command is
 * the "worker" the spec refers to — it polls that table separately from
 * any queue/broker, so a Redis outage at write time can't lose an event,
 * only delay its relay.
 *
 * This command only claims rows and dispatches them as real
 * ShouldQueue jobs (ProcessOutboxEvent, run by Horizon on the "outbox"
 * queue, §61) — it does not fire events or touch final status itself.
 * That split matters: if Redis is down when we try to dispatch, the row
 * just stays "pending" for the next poll instead of the event being
 * lost, which is the same guarantee the outbox already gives at write
 * time, now also covering the relay's own handoff to the queue.
 *
 * No listeners exist yet for these events (Phase 2 will add
 * SendConfirmationEmail, SyncGoogleCalendar, etc. against them) — the
 * write -> relay -> queue -> dispatch pipeline works end to end already,
 * and Phase 2 listeners will "just work" once registered.
 */
class OutboxRelay extends Command
{
    protected $signature = 'outbox:relay
        {--once : Process a single batch and exit, instead of looping forever}
        {--batch=50 : Max messages to claim per poll}
        {--sleep=1 : Seconds to sleep between polls when not using --once}';

    protected $description = 'Relay pending outbox_messages rows as queued jobs (§33, §61)';

    public function handle(): int
    {
        $once = (bool) $this->option('once');

        do {
            $claimed = $this->relayBatch((int) $this->option('batch'));

            if (! $once && $claimed === 0) {
                sleep((int) $this->option('sleep'));
            }
        } while (! $once);

        return self::SUCCESS;
    }

    private function relayBatch(int $batchSize): int
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
            $this->dispatchOne($message);
        }

        return $messages->count();
    }

    private function dispatchOne(OutboxMessage $message): void
    {
        try {
            ProcessOutboxEvent::dispatch($message->id)->onQueue('outbox');
        } catch (Throwable $e) {
            Log::warning('Outbox relay failed to dispatch message to the queue', [
                'outbox_message_id' => $message->id,
                'event_type' => $message->event_type,
                'error' => $e->getMessage(),
            ]);

            $message->update([
                'status' => OutboxStatus::Pending,
                'error' => $e->getMessage(),
                'available_at' => now()->addSeconds(10),
            ]);
        }
    }
}
