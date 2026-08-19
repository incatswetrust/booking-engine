<?php

use App\Domain\Booking\Events\BookingCreated;
use App\Domain\Outbox\OutboxMessage;
use App\Domain\Outbox\OutboxStatus;
use App\Jobs\ProcessOutboxEvent;
use Illuminate\Support\Facades\Event;

it('fires the mapped domain event and marks the message processed', function () {
    Event::fake();

    $message = OutboxMessage::create([
        'event_type' => 'BookingCreated',
        'aggregate_type' => 'Booking',
        'aggregate_id' => 'bkg_test123',
        'payload' => ['booking_id' => 'bkg_test123', 'status' => 'pending'],
    ]);

    (new ProcessOutboxEvent($message->id))->handle();

    Event::assertDispatched(BookingCreated::class, fn ($event) => $event->bookingId === 'bkg_test123'
        && $event->payload['status'] === 'pending');

    $message->refresh();
    expect($message->status)->toBe(OutboxStatus::Processed)
        ->and($message->processed_at)->not->toBeNull();
});

it('increments attempts and rethrows for an unknown event_type', function () {
    $message = OutboxMessage::create([
        'event_type' => 'SomeUnregisteredEvent',
        'aggregate_type' => 'Booking',
        'aggregate_id' => 'bkg_bad',
        'payload' => [],
    ]);

    expect(fn () => (new ProcessOutboxEvent($message->id))->handle())->toThrow(RuntimeException::class);

    $message->refresh();
    expect($message->attempts)->toBe(1)
        ->and($message->error)->not->toBeNull()
        ->and($message->status)->toBe(OutboxStatus::Pending);
});

it('marks the message failed once the job gives up retrying', function () {
    $message = OutboxMessage::create([
        'event_type' => 'BookingCreated',
        'aggregate_type' => 'Booking',
        'aggregate_id' => 'bkg_exhausted',
        'payload' => [],
    ]);

    (new ProcessOutboxEvent($message->id))->failed(new RuntimeException('boom'));

    $message->refresh();
    expect($message->status)->toBe(OutboxStatus::Failed)
        ->and($message->error)->toBe('boom');
});

it('does nothing if the outbox message no longer exists', function () {
    expect(fn () => (new ProcessOutboxEvent(999999))->handle())->not->toThrow(Throwable::class);
});
