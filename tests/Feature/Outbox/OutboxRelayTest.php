<?php

use App\Domain\Outbox\OutboxMessage;
use App\Domain\Outbox\OutboxStatus;
use App\Jobs\ProcessOutboxEvent;
use Illuminate\Support\Facades\Queue;

/**
 * outbox:relay only claims rows and dispatches ProcessOutboxEvent onto
 * the real "outbox" Redis queue (§61) -- it doesn't fire events or
 * decide final success/failure itself anymore, so these tests only
 * cover claiming/dispatching. What the dispatched job actually does is
 * covered by ProcessOutboxEventTest.php.
 */
it('claims pending messages and dispatches them to the outbox queue', function () {
    Queue::fake();

    $message = OutboxMessage::create([
        'event_type' => 'BookingCreated',
        'aggregate_type' => 'Booking',
        'aggregate_id' => 'bkg_test123',
        'payload' => ['booking_id' => 'bkg_test123'],
    ]);

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    Queue::assertPushedOn('outbox', ProcessOutboxEvent::class, fn ($job) => $job->outboxMessageId === $message->id);

    expect($message->refresh()->status)->toBe(OutboxStatus::Processing);
});

it('does not claim a message whose available_at is in the future', function () {
    Queue::fake();

    OutboxMessage::create([
        'event_type' => 'BookingConfirmed',
        'aggregate_type' => 'Booking',
        'aggregate_id' => 'bkg_future',
        'payload' => [],
        'available_at' => now()->addMinutes(5),
    ]);

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    Queue::assertNotPushed(ProcessOutboxEvent::class);
    expect(OutboxMessage::where('aggregate_id', 'bkg_future')->first()->status)->toBe(OutboxStatus::Pending);
});

it('claims and dispatches multiple pending messages in one batch', function () {
    Queue::fake();

    for ($i = 0; $i < 3; $i++) {
        OutboxMessage::create([
            'event_type' => 'BookingCreated',
            'aggregate_type' => 'Booking',
            'aggregate_id' => "bkg_batch_{$i}",
            'payload' => [],
        ]);
    }

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    Queue::assertPushed(ProcessOutboxEvent::class, 3);
    expect(OutboxMessage::where('status', OutboxStatus::Processing)->count())->toBe(3);
});
