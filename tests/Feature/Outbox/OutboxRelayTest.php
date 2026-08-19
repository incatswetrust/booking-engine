<?php

use App\Domain\Booking\Events\BookingConfirmed;
use App\Domain\Booking\Events\BookingCreated;
use App\Domain\Outbox\OutboxMessage;
use App\Domain\Outbox\OutboxStatus;
use Illuminate\Support\Facades\Event;

it('relays pending outbox messages as real events and marks them processed', function () {
    Event::fake();

    $message = OutboxMessage::create([
        'event_type' => 'BookingCreated',
        'aggregate_type' => 'Booking',
        'aggregate_id' => 'bkg_test123',
        'payload' => ['booking_id' => 'bkg_test123', 'status' => 'pending'],
    ]);

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    Event::assertDispatched(BookingCreated::class, fn ($event) => $event->bookingId === 'bkg_test123'
        && $event->payload['status'] === 'pending');

    $message->refresh();
    expect($message->status)->toBe(OutboxStatus::Processed)
        ->and($message->processed_at)->not->toBeNull();
});

it('does not relay a message whose available_at is in the future', function () {
    Event::fake();

    OutboxMessage::create([
        'event_type' => 'BookingConfirmed',
        'aggregate_type' => 'Booking',
        'aggregate_id' => 'bkg_future',
        'payload' => [],
        'available_at' => now()->addMinutes(5),
    ]);

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    Event::assertNotDispatched(BookingConfirmed::class);
    expect(OutboxMessage::where('aggregate_id', 'bkg_future')->first()->status)->toBe(OutboxStatus::Pending);
});

it('retries an unknown event_type with backoff and eventually marks it failed', function () {
    $message = OutboxMessage::create([
        'event_type' => 'SomeUnregisteredEvent',
        'aggregate_type' => 'Booking',
        'aggregate_id' => 'bkg_bad',
        'payload' => [],
    ]);

    $this->artisan('outbox:relay', ['--once' => true, '--max-attempts' => 2])->assertSuccessful();

    $message->refresh();
    expect($message->status)->toBe(OutboxStatus::Pending)
        ->and($message->attempts)->toBe(1)
        ->and($message->error)->not->toBeNull()
        ->and($message->available_at->isFuture())->toBeTrue();

    // Force it back into the polling window and let it fail a second time.
    $message->update(['available_at' => now()->subSecond()]);

    $this->artisan('outbox:relay', ['--once' => true, '--max-attempts' => 2])->assertSuccessful();

    $message->refresh();
    expect($message->status)->toBe(OutboxStatus::Failed)
        ->and($message->attempts)->toBe(2);
});

it('processes multiple pending messages in one batch', function () {
    Event::fake();

    for ($i = 0; $i < 3; $i++) {
        OutboxMessage::create([
            'event_type' => 'BookingCreated',
            'aggregate_type' => 'Booking',
            'aggregate_id' => "bkg_batch_{$i}",
            'payload' => ['booking_id' => "bkg_batch_{$i}"],
        ]);
    }

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    expect(OutboxMessage::where('status', OutboxStatus::Processed)->count())->toBe(3);
});
